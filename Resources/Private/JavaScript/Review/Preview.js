/**
 * The preview of a document: the published version on the left and the version of the
 * workspace on the right.
 */

/**
 * The marker of the messages that are exchanged between the review and the pages in its
 * preview frames - everything else that is posted is ignored.
 *
 * @type {string}
 */
const previewMarker = 'workspace-review-preview';

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
 * Starts the session of the review on the host the preview is loaded from, in a hidden
 * frame.
 *
 * The session of the review is only valid on the host the review is opened on. The frames
 * of the preview are on the domain of the site the document belongs to, and their requests
 * carry no session without the handover - the pages in them are answered with the login.
 * The handover looks up the session of the review with a token and gives that host its
 * cookie; after the handover, the frames are authenticated.
 *
 * Resolves when the handover has happened, or after a few seconds if it has failed - the
 * preview can still be opened, and shows the pages of the preview to a user who has a
 * session on that host already.
 *
 * @param {string} url the url of the handover
 * @returns {Promise<void>}
 */
const startPreviewSession = (url) =>
    new Promise((resolve) => {
        const frame = document.createElement('iframe');
        let finished = false;

        const finish = () => {
            if (finished) {
                return;
            }
            finished = true;
            frame.remove();
            resolve();
        };

        frame.hidden = true;
        frame.style.display = 'none';
        frame.addEventListener('load', finish);
        frame.addEventListener('error', finish);
        frame.setAttribute('src', url);
        document.body.append(frame);
        window.setTimeout(finish, 5000);
    });

/**
 * Opens the preview of the document the button belongs to: the published version on the
 * left and the version of the reviewed workspace on the right.
 *
 * A document that was created in the workspace has no published version - the dialog
 * shows the one of the workspace then.
 *
 * @param {HTMLElement} button
 */
export const openPreview = async (button) => {
    const dialog = previewDialog();
    if (!dialog) {
        return;
    }
    const url = button.getAttribute('data-preview-url') || '';
    const baseUrl = button.getAttribute('data-preview-base-url') || '';
    const handoverUrl = button.getAttribute('data-preview-handover-url') || '';

    if (handoverUrl) {
        await startPreviewSession(handoverUrl);
    }

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
 * The scroll position and the height a frame on another host has reported - the page in
 * such a frame cannot be read, but it tells the review about itself.
 *
 * @type {Map<HTMLElement, {y: number, height: number, innerHeight: number}>}
 */
const previewFrameState = new Map();

/**
 * The positions the review told a frame to scroll to - a frame that answers from its own
 * scroll of such a position is an echo, and not a scroll an editor made.
 *
 * @type {Map<HTMLElement, number>}
 */
const appliedPreviewScrollPositions = new Map();

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
 * The frames are on the domain of the site the document belongs to. That is the domain
 * of the review itself only when the site is the one the review is opened on - a frame
 * on another host cannot be read.
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
 * Whether the page in a frame can be read - a frame on another host cannot.
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
        // the frame is on another host - the browser does not let the page in it be read
        return false;
    }
};

/**
 * Whether a frame can be scrolled to a position. A frame the review cannot read scrolls
 * as far as it has told the review it can - the frames on another host talk about
 * themselves, the others are read.
 *
 * @param {HTMLElement} frame
 * @param {number} position
 * @returns {boolean}
 */
const previewFrameCanScrollTo = (frame, position) => {
    if (!previewFrameCanBeRead(frame)) {
        const state = previewFrameState.get(frame);

        return state !== undefined && position <= state.height - state.innerHeight + 1;
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
    if (previewFrameCanBeRead(frame)) {
        window.scrollTo(0, position);
        return;
    }
    // the page in the frame scrolls when it is told to - the frames on other hosts are
    // on the page of a site, and only the review talks to them
    appliedPreviewScrollPositions.set(frame, position);
    try {
        window.postMessage({ source: 'workspace-review', type: 'scroll', y: position }, '*');
    } catch (exception) {
        // the frame is gone with the close of the dialog
    }
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
 * On the host of the review a scroll of a frame is read; on another host the page in the
 * frame tells the review about its scrolls - they arrive as messages from the window of
 * a frame.
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
                // the frame is on another host - its scrolls arrive as messages instead
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

    // The scrolls of the frames on another host.
    window.addEventListener('message', (event) => {
        const data = event.data;
        if (!data || data.source !== previewMarker || data.type !== 'scroll') {
            return;
        }
        const frame = previewFrames().find((candidate) => candidate.contentWindow === event.source);
        if (!frame || typeof data.y !== 'number') {
            return;
        }
        const echo = appliedPreviewScrollPositions.get(frame) === data.y;
        previewFrameState.set(frame, {
            y: data.y,
            height: data.height ?? 0,
            innerHeight: data.innerHeight ?? 0,
        });
        if (echo) {
            // the frame scrolled because the review told it to - no editor scrolled here
            return;
        }
        syncPreviewFramesTo(frame, data.y);
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
    const position = previewFrameCanBeRead(lastScrolledPreviewFrame)
        ? windowOfPreviewFrame(lastScrolledPreviewFrame)?.scrollY
        : previewFrameState.get(lastScrolledPreviewFrame)?.y;
    if (typeof position !== 'number') {
        return;
    }

    syncPreviewFramesTo(lastScrolledPreviewFrame, position);
};
