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
 * The frames of the preview.
 *
 * @returns {HTMLElement[]}
 */
const previewFrames = () => Array.from(previewDialog()?.querySelectorAll('.workspace-review-preview-frame') || []);

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
 * The window of a frame.
 *
 * The frames are on the host the review is opened on, so they are on its origin: the review
 * reads the page in them and scrolls it.
 *
 * @param {HTMLElement} frame
 * @returns {Window|null}
 */
const windowOfPreviewFrame = (frame) => {
    try {
        return frame.contentWindow;
    } catch (exception) {
        // the frame cannot be reached - its scroll position is out of reach
        return null;
    }
};

/**
 * Whether the page in a frame can be read.
 *
 * @param {HTMLElement} frame
 * @returns {boolean}
 */
const previewFrameCanBeRead = (frame) => {
    const window = windowOfPreviewFrame(frame);
    if (!window) {
        return false;
    }
    try {
        return window.document.documentElement !== null;
    } catch (exception) {
        // the browser does not let the page in the frame be read
        return false;
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
    if (!previewFrameCanBeRead(frame)) {
        return false;
    }
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
    if (!window) {
        return;
    }
    window.scrollTo(0, position);
};

/**
 * Keeps the scroll position of the other previews in step with the one that was scrolled.
 *
 * The frames show the same page in two versions. A frame that cannot scroll as far as
 * the other one - a shorter version of the page - is left where it is, so that the two do
 * not push each other back and forth.
 *
 * @param {HTMLElement} frame the frame that was scrolled
 * @param {number} position
 */
const syncPreviewFramesTo = (frame, position) => {
    lastScrolledPreviewFrame = frame;
    if (!previewScrollSyncEnabled()) {
        // the editor has switched the sync off - the frames are scrolled on their own
        return;
    }
    previewFrames().forEach((other) => {
        if (other === frame) {
            return;
        }
        if (!previewFrameCanScrollTo(other, position)) {
            return;
        }
        scrollPreviewFrameTo(other, position);
    });
};

/**
 * Keeps the scroll position of the two previews in step.
 *
 * The frames are on the host the review is opened on, so the review reads the scroll of a
 * frame from its window and scrolls the other frame itself.
 */
export const setupPreviewScrollSync = () => {
    const dialog = previewDialog();
    if (!dialog) {
        return;
    }
    previewFrames().forEach((frame) => {
        frame.addEventListener('load', () => {
            const window = windowOfPreviewFrame(frame);
            if (!window || !previewFrameCanBeRead(frame)) {
                // the page in the frame cannot be read - its scrolls cannot be followed
                return;
            }
            window.addEventListener(
                'scroll',
                () => {
                    syncPreviewFramesTo(frame, window.scrollY);
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
    const position = windowOfPreviewFrame(lastScrolledPreviewFrame)?.scrollY;
    if (typeof position !== 'number') {
        return;
    }

    syncPreviewFramesTo(lastScrolledPreviewFrame, position);
};
