/**
 * The preview of a document: the published version on the left and the version of the
 * workspace on the right.
 */
/**
 * The dialog of the preview.
 *
 * @returns {HTMLElement|null}
 */
const previewDialog = () => document.getElementById('workspace-review-preview-dialog');

/**
 * Opens the preview of the document the button belongs to: the published version on the
 * left and the version of the reviewed workspace on the right.
 *
 * A document that was created in the workspace has no published version - the dialog
 * shows the one of the workspace then.
 *
 * @param {HTMLElement} button
 */
export const openPreview = (button) => {
    const dialog = previewDialog();
    if (!dialog) {
        return;
    }
    const url = button.getAttribute('data-preview-url') || '';
    const baseUrl = button.getAttribute('data-preview-base-url') || '';

    const title = dialog.querySelector('[data-role="preview-title"]');
    if (title) {
        title.textContent = button.getAttribute('data-preview-title') || '';
    }

    const basePane = dialog.querySelector('[data-pane="base"]');
    const baseFrame = dialog.querySelector('[data-role="preview-base-frame"]');
    const currentFrame = dialog.querySelector('[data-role="preview-current-frame"]');

    if (currentFrame) {
        currentFrame.setAttribute('src', url);
    }
    if (baseUrl && baseFrame && basePane) {
        baseFrame.setAttribute('src', baseUrl);
        basePane.hidden = false;
    } else {
        if (baseFrame) {
            baseFrame.removeAttribute('src');
        }
        if (basePane) {
            basePane.hidden = true;
        }
    }

    if (typeof dialog.showModal === 'function') {
        dialog.showModal();
    }
};

/**
 * Closes the preview - the pages in the frames stop running with it.
 */
export const closePreview = () => {
    const dialog = previewDialog();
    if (!dialog) {
        return;
    }
    if (typeof dialog.close === 'function') {
        dialog.close();
    }
    dialog.querySelectorAll('iframe').forEach((frame) => frame.removeAttribute('src'));
};

/**
 * The frame that was scrolled last - the one the other frame follows when the scroll
 * sync is switched on again.
 *
 * @type {HTMLElement|null}
 */
let lastScrolledPreviewFrame = null;

/**
 * Whether the two previews are scrolled in step.
 *
 * The editor can switch the sync off - when the content of the page changed a lot, the
 * two versions are worth looking at at different places.
 *
 * @returns {boolean}
 */
const previewScrollSyncEnabled = () => {
    const checkbox = document.querySelector('[data-role="preview-sync"]');

    return !checkbox || checkbox.checked;
};

/**
 * The window of a frame - the frames are on the same host, so the page in them can be
 * read. A frame on another host cannot be reached.
 *
 * @param {HTMLElement} frame
 * @returns {Window|null}
 */
const windowOfPreviewFrame = (frame) => {
    try {
        return frame.contentWindow;
    } catch (exception) {
        // the frame is on another host - its scroll position cannot be reached
        return null;
    }
};

/**
 * Whether a frame can be scrolled to a position.
 *
 * @param {HTMLElement} frame
 * @param {number} position
 * @returns {boolean}
 */
const previewFrameCanScrollTo = (frame, position) => {
    const window = windowOfPreviewFrame(frame);
    if (!window) {
        return false;
    }
    const height = window.document.documentElement.scrollHeight;

    return position <= height - window.innerHeight + 1;
};

/**
 * Scrolls a frame to a position.
 *
 * @param {HTMLElement} frame
 * @param {number} position
 */
const scrollPreviewFrameTo = (frame, position) => {
    const window = windowOfPreviewFrame(frame);
    if (window) {
        window.scrollTo(0, position);
    }
};

/**
 * Keeps the scroll position of the two previews in step.
 *
 * The frames show the same page in two versions, and they are on the same host - so the
 * scroll position of the page in a frame can be read and set. A frame that cannot scroll
 * as far as the other one - a shorter version of the page - is left where it is, so that
 * the two do not push each other back and forth.
 */
export const setupPreviewScrollSync = () => {
    const dialog = previewDialog();
    if (!dialog) {
        return;
    }
    const frames = Array.from(dialog.querySelectorAll('.workspace-review-preview-frame'));

    frames.forEach((frame) => {
        frame.addEventListener('load', () => {
            const window = windowOfPreviewFrame(frame);
            if (!window) {
                return;
            }
            window.addEventListener(
                'scroll',
                () => {
                    lastScrolledPreviewFrame = frame;
                    if (!previewScrollSyncEnabled()) {
                        // the editor has switched the sync off - the frames are scrolled on
                        // their own
                        return;
                    }
                    const position = window.scrollY;
                    frames.forEach((other) => {
                        if (other === frame) {
                            return;
                        }
                        if (!previewFrameCanScrollTo(other, position)) {
                            return;
                        }
                        scrollPreviewFrameTo(other, position);
                    });
                },
                { passive: true },
            );
        });
    });
};

/**
 * Switches the scroll sync of the preview on and off.
 *
 * When it is switched on again, the frame that was scrolled last leads - the other one
 * follows it.
 */
export const togglePreviewScrollSync = () => {
    if (!previewScrollSyncEnabled() || !lastScrolledPreviewFrame) {
        return;
    }
    const dialog = previewDialog();
    if (!dialog) {
        return;
    }
    const frames = Array.from(dialog.querySelectorAll('.workspace-review-preview-frame'));
    const window = windowOfPreviewFrame(lastScrolledPreviewFrame);
    if (!window) {
        return;
    }
    const position = window.scrollY;
    frames.forEach((frame) => {
        if (frame === lastScrolledPreviewFrame) {
            return;
        }
        if (!previewFrameCanScrollTo(frame, position)) {
            return;
        }
        scrollPreviewFrameTo(frame, position);
    });
};
