<?php

namespace lameco\dash;

use Craft;
use craft\base\Element;
use craft\base\Event;
use craft\elements\Asset;
use craft\events\AuthorizationCheckEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterElementSourcesEvent;
use craft\helpers\Json;
use craft\services\Elements;
use lameco\dash\services\DashSync;
use Throwable;

/**
 * Keeps the Dash volume read-only where it has to be, while leaving alt text editable.
 *
 * Volume permissions cannot do this on their own: `checkPermission()` resolves to
 * `User::can()`, which returns true unconditionally for admins. So an admin sees a live
 * Upload button on a read-only volume, and the Filename input in the asset editor is gated
 * only by `$static` — one stray keystroke there triggers a renameFile() the filesystem
 * refuses.
 *
 * Saving is deliberately *not* blocked. Dash ships no alt-text field, so if editors could not
 * author alt text in Craft there would be nowhere to author it at all, and 282 images would go
 * live on a healthcare site without any. What is blocked is everything that would move bytes
 * or identity: uploads, replacements, renames, moves, duplication and deletion.
 *
 * None of this touches DashSync. saveElement() and deleteElement() do not consult
 * canSave()/canDelete(), and the sync sets folderId and filename directly rather than through
 * newFolderId/newFilename, so it trips none of the guards below.
 */
class ReadOnlyGuard
{
    /**
     * Deleting is blocked for a reason beyond the filesystem: an asset deleted in Craft while
     * it still exists in Dash returns on the next sync as a *new* element with a new id,
     * silently orphaning every relation that pointed at the old one. Duplicating is blocked
     * because it copies the file, which the filesystem refuses.
     */
    private const DENIED_EVENTS = [
        Elements::EVENT_AUTHORIZE_DELETE,
        Elements::EVENT_AUTHORIZE_DELETE_FOR_SITE,
        Elements::EVENT_AUTHORIZE_DUPLICATE,
        Elements::EVENT_AUTHORIZE_DUPLICATE_AS_DRAFT,
    ];

    public static function register(): void
    {
        foreach (self::DENIED_EVENTS as $name) {
            Event::on(Elements::class, $name, static function(AuthorizationCheckEvent $event) {
                if (self::isDashAsset($event->element)) {
                    $event->authorized = false;
                }
            });
        }

        Event::on(Asset::class, Element::EVENT_BEFORE_SAVE, static function(ModelEvent $event) {
            /** @var Asset $asset */
            $asset = $event->sender;

            if (!self::isDashAsset($asset)) {
                return;
            }

            // The four properties Asset::afterSave() keys off to touch the filesystem. Any of
            // them set means an upload, a replacement, a rename or a move — none of which a
            // read-only filesystem can serve, and all of which would break the path identity
            // the Dash id mapping depends on.
            foreach (['newLocation', 'newFilename', 'newFolderId', 'tempFilePath'] as $attribute) {
                if (!isset($asset->$attribute)) {
                    continue;
                }

                $message = Craft::t(
                    '_craft-dash',
                    'Dash assets cannot be renamed, moved, replaced or uploaded from Craft. Make the change in Dash instead — the site follows within a few minutes.',
                );

                // Also against `newLocation`, whatever tripped the guard: that is the attribute
                // the asset editor's Filename field reads its errors from, so this is what puts
                // the message next to the input the editor typed into.
                $asset->addError($attribute, $message);
                $asset->addError('newLocation', $message);
                $event->isValid = false;

                return;
            }
        });

        // Two controls in the asset editor survive everything above, because Craft gates them
        // on things that do not hold here. The Filename input is `'disabled' => $static`, and
        // $static follows canSave — which has to stay true for alt text. The Edit Image button
        // is gated on the `editImages` permission, which admins bypass.
        //
        // Neither has a server-side hook that can reach it while saving is allowed, so they are
        // taken out of the page here. This is presentation only: the real enforcement is the
        // before-save guard above, which refuses the write whatever the markup says.
        Event::on(Asset::class, Element::EVENT_DEFINE_META_FIELDS_HTML, static function(DefineHtmlEvent $event) {
            if (!self::isDashAsset($event->sender)) {
                return;
            }

            $filenameHint = Json::encode(Craft::t(
                '_craft-dash',
                'Managed in Dash. Rename the file there and the site follows within a few minutes.',
            ));
            $titleHint = Json::encode(Craft::t(
                '_craft-dash',
                'Managed in Dash. Change the title there and the site follows within a few minutes.',
            ));

            // Fires while the editor sidebar renders, which is where both controls live. Craft
            // registers its own JS from this same path.
            $badge = Json::encode(Craft::t('app', 'Read Only'));

            Craft::$app->getView()->registerJs(<<<JS
(() => {
    // Craft's own badge markup and class, so it picks up the control panel's styling and the
    // editor's language rather than looking bolted on. Craft renders this from \$static, which
    // cannot be true here — saving has to stay possible for the alt text field.
    const markReadOnly = (input, hint, disable) => {
        if (!input) {
            return;
        }

        if (disable) {
            // Disabled rather than readonly: readonly still looks like an editable input, and
            // still posts its value. Disabled is what Craft's own static rendering uses, and the
            // browser leaves the field out of the request entirely — nothing to refuse.
            input.disabled = true;
        }

        input.setAttribute('title', hint);

        const heading = input.closest('.field')?.querySelector('.heading');

        if (!heading || heading.querySelector('.read-only-badge')) {
            return;
        }

        const span = document.createElement('span');
        span.className = 'read-only-badge';
        span.textContent = $badge;

        // Before the spacer, which is where Craft puts it — appending after would push the badge
        // to the far right, away from the label it describes.
        const spacer = heading.querySelector('.flex-grow');
        spacer ? heading.insertBefore(span, spacer) : heading.appendChild(span);
    };

    markReadOnly(document.querySelector('[name="newFilename"], #new-filename'), $filenameHint, true);

    // Title only gets the badge. It is already non-editable from the field layout's own readonly
    // flag, and it must keep posting its value — the attribute is required, and a disabled input
    // would be left out of the request and fail validation on save.
    markReadOnly(document.querySelector('#title, [name="title"]'), $titleHint, false);

    // Stable class from Craft's own markup, so this does not depend on the button's label.
    document.querySelectorAll('.edit-btn').forEach((button) => button.remove());
})();
JS);
        });

        Event::on(Asset::class, Element::EVENT_REGISTER_SOURCES, static function(RegisterElementSourcesEvent $event) {
            $volume = Craft::$app->getVolumes()->getVolumeByHandle(DashSync::VOLUME_HANDLE);

            if ($volume === null) {
                return;
            }

            $key = 'volume:' . $volume->uid;

            foreach ($event->sources as &$source) {
                if (($source['key'] ?? null) !== $key) {
                    continue;
                }

                // What the asset index reads to decide whether to render the Upload button and
                // accept a drag-and-drop or a move into this volume. Stopping it here means an
                // editor is never offered an action that could only fail.
                $source['data']['can-upload'] = false;
                $source['data']['can-move-to'] = false;
            }
        });
    }

    private static function isDashAsset(?object $element): bool
    {
        if (!$element instanceof Asset) {
            return false;
        }

        try {
            return $element->getVolume()->handle === DashSync::VOLUME_HANDLE;
        } catch (Throwable) {
            // A temporary upload has no real volume yet, and a missing one throws. Neither is
            // a Dash asset, and neither is worth failing a save or an authorization check over.
            return false;
        }
    }
}
