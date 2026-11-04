/**
 * @typedef {Object} Notification
 * @property {string} severity
 * @property {string} title
 * @property {string} message
 * @property {number} code
 */

/**
 * @typedef {Object} EventDetails
 * @property {XMLHttpRequest} xhr
 */

/**
 * @typedef {Object} HtmxEvent
 * @property {EventDetails} detail
 */

/**
 * @typedef {Object} ChangeSelection
 * @property {string} document the document the change belongs to
 */

document.addEventListener('DOMContentLoaded', () => {
    if (!window.htmx) {
        console.error('htmx is not loaded');
        return;
    }

    /**
     * Show flash messages after successful requests
     */
    htmx.on(
        'htmx:afterRequest',
        /** @param {HtmxEvent} e */ (e) => {
            const flashMessagesJson = e.detail.xhr.getResponseHeader('X-Flow-FlashMessages');
            if (!flashMessagesJson) {
                return;
            }

            /** @type Notification[] */
            const flashMessages = JSON.parse(flashMessagesJson);
            flashMessages.forEach(({ severity, title, message }) => {
                if (title) {
                    NeosCMS.Notification[severity.toLowerCase()](title, message);
                } else {
                    NeosCMS.Notification[severity.toLowerCase()](message);
                }
            });
        },
    );

    /**
     * Show error notifications for failed requests if no flash messages are present
     */
    htmx.on(
        'htmx:responseError',
        /** @param {HtmxEvent} e */ (e) => {
            const flashMessagesJson = e.detail.xhr.getResponseHeader('X-Flow-FlashMessages');
            if (flashMessagesJson) {
                return;
            }

            const { status, statusText } = e.detail.xhr;
            NeosCMS.Notification.error(`Error ${status}: ${statusText}`);
        },
    );

    /**
     * The selection of the review.
     *
     * A selection holds single changes and whole documents. A document stands for all of its
     * changes - and for the documents below it, which are selected with it.
     *
     * The selection lives in the browser, because the changes of a document are swapped into
     * the page: it survives the change of the document that is open.
     */
    const selection = {
        /** @type {Set<string>} */
        documents: new Set(),
        /** @type {Map<string, ChangeSelection>} */
        changes: new Map(),
    };

    /**
     * The documents of the list, by the document they sit below.
     *
     * @type {Map<string, string[]>}
     */
    let documentsByParent = new Map();

    /**
     * The rows of the document list.
     *
     * @returns {HTMLElement[]}
     */
    const documentRows = () => Array.from(document.querySelectorAll('.workspace-review-document-row'));

    /**
     * The row of a document, addressed by its context path.
     *
     * @param {string} contextPath
     * @returns {HTMLElement|undefined}
     */
    const documentRow = (contextPath) =>
        documentRows().find((row) => row.getAttribute('data-document') === contextPath);

    /**
     * The number of the changes of a document, as the list of the documents knows it.
     *
     * @param {string} contextPath
     * @returns {number}
     */
    const changesCountOfDocument = (contextPath) => {
        const row = documentRow(contextPath);

        return row ? parseInt(row.getAttribute('data-count') || '0', 10) : 0;
    };

    /**
     * Reads the documents of the list again, so that the documents below a document can be
     * found.
     */
    const readDocumentList = () => {
        documentsByParent = new Map();
        documentRows().forEach((row) => {
            const parent = row.getAttribute('data-parent') || '';
            if (!documentsByParent.has(parent)) {
                documentsByParent.set(parent, []);
            }
            documentsByParent.get(parent).push(row.getAttribute('data-document') || '');
        });
    };

    /**
     * The documents below a document, the document itself included.
     *
     * @param {string} contextPath
     * @returns {string[]}
     */
    const documentsBelow = (contextPath) => {
        const found = [contextPath];
        const collect = (parent) => {
            (documentsByParent.get(parent) || []).forEach((child) => {
                found.push(child);
                collect(child);
            });
        };
        collect(contextPath);

        return found;
    };

    /**
     * Whether a document is covered by the selection.
     *
     * A document that is selected stands for all of its changes - and for the changes of the
     * documents below it.
     *
     * @param {string} contextPath
     * @returns {boolean}
     */
    const isCoveredBySelection = (contextPath) => {
        if (selection.documents.has(contextPath)) {
            return true;
        }

        let parent = '';
        const row = documentRow(contextPath);
        if (row) {
            parent = row.getAttribute('data-parent') || '';
        }
        while (parent !== '') {
            if (selection.documents.has(parent)) {
                return true;
            }
            const parentRow = documentRow(parent);
            parent = parentRow ? parentRow.getAttribute('data-parent') || '' : '';
        }

        return false;
    };

    /**
     * The number of the changes of a document that are selected on their own.
     *
     * @param {string} contextPath
     * @returns {number}
     */
    const selectedChangesOfDocument = (contextPath) => {
        let count = 0;
        selection.changes.forEach((change) => {
            if (change.document === contextPath) {
                count++;
            }
        });

        return count;
    };

    /**
     * Selects a document with the documents below it.
     *
     * @param {string} contextPath
     */
    const selectDocument = (contextPath) => {
        documentsBelow(contextPath).forEach((document) => {
            selection.documents.add(document);
            // the changes of the document are covered by the document itself
            Array.from(selection.changes.keys()).forEach((change) => {
                if (selection.changes.get(change).document === document) {
                    selection.changes.delete(change);
                }
            });
        });
    };

    /**
     * Deselects a document with the documents below it.
     *
     * @param {string} contextPath
     */
    const deselectDocument = (contextPath) => {
        documentsBelow(contextPath).forEach((document) => {
            selection.documents.delete(document);
            Array.from(selection.changes.keys()).forEach((change) => {
                if (selection.changes.get(change).document === document) {
                    selection.changes.delete(change);
                }
            });
        });
    };

    /**
     * The checkboxes of the changes that are open.
     *
     * @returns {HTMLInputElement[]}
     */
    const changeCheckboxes = () =>
        Array.from(document.querySelectorAll('#document-changes .neos-content-change input[type="checkbox"]'));

    /**
     * The checkbox of the change with the given context path.
     *
     * @param {string} contextPath
     * @returns {HTMLInputElement|undefined}
     */
    const checkboxOfChange = (contextPath) => changeCheckboxes().find((checkbox) => checkbox.value === contextPath);

    /**
     * The changes that have to be selected together with a change.
     *
     * A node can only be published if its parents exist in the workspace it is published to. The
     * parents of a node that was created in the workspace are created there as well, and they are
     * part of the changes of the document: such a parent is selected together with its
     * child, and deselected together with it.
     *
     * @param {HTMLInputElement} checkbox
     * @returns {string[]}
     */
    const requiredParentsOf = (checkbox) =>
        (checkbox.dataset.requiredParents || '').split(' ').filter((contextPath) => contextPath !== '');

    /**
     * @param {HTMLInputElement} checkbox
     */
    const selectWithItsParents = (checkbox) => {
        requiredParentsOf(checkbox).forEach((contextPath) => {
            const parent = checkboxOfChange(contextPath);
            if (parent && !parent.checked) {
                parent.checked = true;
                selectWithItsParents(parent);
            }
        });
    };

    /**
     * @param {HTMLInputElement} checkbox
     */
    const deselectWithItsChildren = (checkbox) => {
        changeCheckboxes().forEach((child) => {
            if (child === checkbox || !child.checked) {
                return;
            }
            if (!requiredParentsOf(child).includes(checkbox.value)) {
                return;
            }
            child.checked = false;
            deselectWithItsChildren(child);
        });
    };

    /**
     * The document that is open, addressed by its context path.
     *
     * @returns {string}
     */
    const openDocument = () => {
        const changes = document.getElementById('document-changes');
        return changes ? changes.getAttribute('data-document') || '' : '';
    };

    /**
     * Replaces the selection of a document with its changes.
     *
     * A document that is selected stands for all of its changes. When a single change is
     * taken out of it, the document is expanded into the changes it has.
     *
     * @param {string} contextPath
     */
    const expandDocumentIntoChanges = (contextPath) => {
        selection.documents.delete(contextPath);
        changeCheckboxes().forEach((checkbox) => {
            selection.changes.set(checkbox.value, { document: contextPath });
        });
    };

    /**
     * Takes the state of the checkboxes of the open document into the selection.
     */
    const applyChangesOfOpenDocument = () => {
        const document = openDocument();
        if (document === '') {
            return;
        }
        if (selection.documents.has(document)) {
            // the document was selected as a whole and one of its changes was taken out of it
            expandDocumentIntoChanges(document);
        }
        changeCheckboxes().forEach((checkbox) => {
            if (checkbox.checked) {
                selection.changes.set(checkbox.value, { document });
            } else {
                selection.changes.delete(checkbox.value);
            }
        });
    };

    /**
     * Shows the selection in the checkboxes of the changes that are open.
     */
    const restoreSelectionInChanges = () => {
        const document = openDocument();
        const covered = document !== '' && isCoveredBySelection(document);
        changeCheckboxes().forEach((checkbox) => {
            checkbox.checked = covered || selection.changes.has(checkbox.value);
        });
    };

    /**
     * Removes the parts of the selection that are not there any more.
     */
    const pruneSelection = () => {
        // the documents that are not in the list any more are gone
        Array.from(selection.documents).forEach((contextPath) => {
            if (!documentRow(contextPath)) {
                selection.documents.delete(contextPath);
            }
        });
        // the changes of the document that is open
        const document = openDocument();
        if (document === '') {
            return;
        }
        const existing = changeCheckboxes().map((checkbox) => checkbox.value);
        Array.from(selection.changes.keys()).forEach((change) => {
            if (selection.changes.get(change).document === document && !existing.includes(change)) {
                selection.changes.delete(change);
            }
        });
    };

    /**
     * The number of the changes that are selected.
     *
     * A selected document stands for all of its changes, and the number of them is known -
     * the list of the documents carries it.
     *
     * @returns {number}
     */
    const selectedChangesCount = () => {
        let count = 0;
        selection.documents.forEach((contextPath) => {
            count += changesCountOfDocument(contextPath);
        });
        selection.changes.forEach((change) => {
            if (isCoveredBySelection(change.document)) {
                return;
            }
            count++;
        });

        return count;
    };

    /**
     * The selection as the parameters of the request.
     *
     * The urls of the buttons cannot change - htmx reads them once, when the page is loaded.
     * What the buttons send along is the selection, and it travels in the hidden fields of
     * the review.
     */
    const updateSelectionInputs = () => {
        const container = document.getElementById('reviewSelection');
        if (!container) {
            return;
        }
        container.innerHTML = '';

        const input = (name, value) => {
            const element = document.createElement('input');
            element.type = 'hidden';
            element.name = name;
            element.value = value;
            container.appendChild(element);
        };

        // the changes that are not covered by a selected document
        selection.changes.forEach((change, contextPath) => {
            if (isCoveredBySelection(change.document)) {
                return;
            }
            input('moduleArguments[nodes][]', contextPath);
        });
        // the documents that are selected
        selection.documents.forEach((contextPath) => {
            input('moduleArguments[documents][]', contextPath);
        });
        // the document that is open - it is the one the buttons act on when nothing is
        // selected
        input('moduleArguments[document]', openDocument());
    };

    /**
     * The state of the checkboxes of the document list.
     */
    const updateDocumentRows = () => {
        documentRows().forEach((row) => {
            const contextPath = row.getAttribute('data-document') || '';
            const total = changesCountOfDocument(contextPath);
            const selected = selectedChangesOfDocument(contextPath);
            const checkbox = row.querySelector('.workspace-review-document-select');
            if (!checkbox) {
                return;
            }

            const covered = isCoveredBySelection(contextPath);
            const complete = total > 0 && selected === total;
            checkbox.checked = covered || complete;

            // a part of the changes is selected - the checkbox shows a greyed check
            const partial = !checkbox.checked && selected > 0;
            checkbox.setAttribute('data-state', partial ? 'partial' : checkbox.checked ? 'full' : 'none');
            row.setAttribute('data-state', partial ? 'partial' : checkbox.checked ? 'full' : 'none');
        });
    };

    /**
     * The text of the hint of a document: what changed in it, and how much of it is selected.
     *
     * @param {HTMLElement} row
     * @returns {string} the changes of the document
     */
    const documentHintChanges = (row) => {
        const total = changesCountOfDocument(row.getAttribute('data-document') || '');
        const number = (template, value) => (template || '').replace('{0}', String(value));

        const kinds = [];
        const newCount = parseInt(row.getAttribute('data-counts-new') || '0', 10);
        const changedCount = parseInt(row.getAttribute('data-counts-changed') || '0', 10);
        const removedCount = parseInt(row.getAttribute('data-counts-removed') || '0', 10);
        if (newCount > 0) {
            kinds.push(number(row.getAttribute('data-hint-new'), newCount));
        }
        if (changedCount > 0) {
            kinds.push(number(row.getAttribute('data-hint-changed'), changedCount));
        }
        if (removedCount > 0) {
            kinds.push(number(row.getAttribute('data-hint-removed'), removedCount));
        }

        const changes =
            total === 1
                ? number(row.getAttribute('data-hint-changes-one'), 1)
                : number(row.getAttribute('data-hint-changes'), total);

        return kinds.length > 0 ? changes + ': ' + kinds.join(', ') : changes;
    };

    /**
     * The text of the hint of a document: how many of its changes are selected.
     *
     * @param {HTMLElement} row
     * @returns {string}
     */
    const documentHintSelection = (row) => {
        const contextPath = row.getAttribute('data-document') || '';
        const total = changesCountOfDocument(contextPath);
        const selected = selectedChangesOfDocument(contextPath);
        const number = (template, ...values) => {
            let text = template || '';
            values.forEach((value, index) => {
                text = text.replace('{' + index + '}', String(value));
            });
            return text;
        };

        if (isCoveredBySelection(contextPath)) {
            return row.getAttribute('data-hint-all') || '';
        }
        if (selected > 0) {
            return number(row.getAttribute('data-hint-some'), selected, total);
        }

        return row.getAttribute('data-hint-none') || '';
    };

    /**
     * Puts a popup of the review where it belongs.
     *
     * The popups are in the layer above the page, so they do not move with it - they have to
     * be put in place again when the page or the list of the documents is scrolled.
     *
     * @param {HTMLElement} popup
     * @param {HTMLElement} anchor the element the popup belongs to
     * @param {string} placement 'below' or 'above'
     * @param {string} alignment 'left' or 'right' - the edge of the popup that is aligned with
     *                        the edge of the anchor
     */
    const positionPopup = (popup, anchor, placement, alignment = 'left') => {
        const anchorPosition = anchor.getBoundingClientRect();
        const popupSize = popup.getBoundingClientRect();
        const margin = 8;

        if (placement === 'above') {
            popup.style.right = window.innerWidth - anchorPosition.right + 'px';
            popup.style.bottom = window.innerHeight - anchorPosition.top + 'px';
            popup.style.left = 'auto';
            popup.style.top = 'auto';
            return;
        }

        if (alignment === 'right') {
            // The right edge of the popup is aligned with the right edge of the item, so that
            // it does not cover the content beside the list.
            // The width of the window includes the scrollbar, the position of the item does
            // not - so the width of the page is the one to calculate with.
            const pageWidth = document.documentElement.clientWidth;
            const pageHeight = document.documentElement.clientHeight;
            popup.style.right =
                Math.max(margin, Math.min(pageWidth - anchorPosition.right, pageWidth - popupSize.width - margin)) +
                'px';
            popup.style.top =
                Math.max(margin, Math.min(anchorPosition.bottom, pageHeight - popupSize.height - margin)) + 'px';
            popup.style.left = 'auto';
            popup.style.bottom = 'auto';
            return;
        }

        popup.style.left =
            Math.max(margin, Math.min(anchorPosition.left, window.innerWidth - popupSize.width - margin)) + 'px';
        popup.style.top =
            Math.max(margin, Math.min(anchorPosition.bottom, window.innerHeight - popupSize.height - margin)) + 'px';
        popup.style.right = 'auto';
        popup.style.bottom = 'auto';
    };

    /**
     * Shows the hint of a document, below its row.
     *
     * @param {HTMLElement} row
     */
    const showDocumentHint = (row) => {
        const hint = row.querySelector('.workspace-review-document-hint');
        if (!hint) {
            return;
        }
        const changes = hint.querySelector('.workspace-review-document-hint-changes');
        const selection = hint.querySelector('.workspace-review-document-hint-selection');
        if (changes) {
            changes.innerHTML = documentHintChanges(row);
        }
        if (selection) {
            selection.innerHTML = documentHintSelection(row);
        }

        positionPopup(hint, row, 'below', 'right');
        if (typeof hint.showPopover === 'function' && !hint.matches(':popover-open')) {
            hint.showPopover();
        }
        // now that the popup knows its size, it is put in place once more
        positionPopup(hint, row, 'below', 'right');
        openDocumentHint = hint;
    };

    /**
     * Hides the hint of a document.
     *
     * @param {HTMLElement} row
     */
    const hideDocumentHint = (row) => {
        const hint = row.querySelector('.workspace-review-document-hint');
        if (hint && typeof hint.hidePopover === 'function' && hint.matches(':popover-open')) {
            hint.hidePopover();
        }
        if (openDocumentHint === hint) {
            openDocumentHint = null;
        }
    };

    /**
     * The state of the buttons that publish and discard.
     *
     * Without a selection they act on the document that is open, with a selection they act on
     * the selected changes - the label of the button says which of the two it does. The menu
     * next to the button holds the other options.
     *
     * @param {number} selectionCount
     */
    const updateActionCombos = (selectionCount) => {
        const hasSelection = selectionCount > 0;

        document.querySelectorAll('.workspace-review-combo').forEach((combo) => {
            const button = combo.querySelector('.workspace-review-combo-main');
            if (!button) {
                return;
            }

            const label = button.querySelector('.workspace-review-combo-label');
            if (label) {
                // one change and more than one change have their own wording
                const template = hasSelection
                    ? selectionCount === 1
                        ? button.getAttribute('data-label-with-one-selection')
                        : button.getAttribute('data-label-with-selection')
                    : button.getAttribute('data-label');
                label.innerHTML = (template || '').replace('{0}', String(selectionCount));
            }

            // the option of the document is only of use when something is selected - the
            // button itself is the document then
            const documentOption = combo.querySelector('.workspace-review-combo-option-document');
            if (documentOption) {
                documentOption.hidden = !hasSelection;
            }
        });
    };

    /**
     * The buttons that publish and discard are only enabled when there is something to do:
     * a selection or a document that is open, and for publishing the permission to publish.
     *
     * The changes of a document are swapped into the page, so the state is updated after
     * every answer as well.
     */
    const updateActionButtons = () => {
        const selectionCount = selectedChangesCount();

        updateActionCombos(selectionCount);

        const hasDocument = openDocument() !== '';
        const publishButton = document.getElementById('publishComboButton');
        if (publishButton) {
            const canPublish = publishButton.getAttribute('data-can-publish') !== 'false';
            publishButton.disabled = !canPublish || (!hasDocument && selectionCount === 0);
        }

        const discardButton = document.getElementById('discardComboButton');
        if (discardButton) {
            discardButton.disabled = !hasDocument && selectionCount === 0;
        }
    };

    /**
     * Brings the selection into the page: the checkboxes of the document list, the checkboxes
     * of the changes and the buttons.
     */
    const updateSelection = () => {
        readDocumentList();
        pruneSelection();
        restoreSelectionInChanges();
        updateDocumentRows();
        updateSelectionInputs();
        updateActionButtons();
    };

    /**
     * The menus of the combo buttons and the list of the presets are popups of the browser:
     * they sit in the layer above the page, so that the footer of the core and the sidebar of
     * the review cannot cut them off, and only one of them can be open at a time.
     *
     * The browser does not know where a popup belongs - the script puts it next to the
     * button that opens it.
     */
    const setupPopovers = () => {
        document.querySelectorAll('[popover]').forEach((popup) => {
            const trigger = document.querySelector('[popovertarget="' + popup.id + '"]');
            if (!trigger) {
                return;
            }

            popupsWithTrigger.push({ popup, trigger });

            popup.addEventListener('beforetoggle', (event) => {
                if (event.newState !== 'open') {
                    return;
                }
                positionPopup(popup, trigger, popup.getAttribute('data-placement') === 'below' ? 'below' : 'above');
            });

            popup.addEventListener('toggle', (event) => {
                trigger.setAttribute('aria-expanded', event.newState === 'open' ? 'true' : 'false');
            });

            // an option acts - the popup closes
            popup.querySelectorAll('a, .workspace-review-combo-option').forEach((option) => {
                option.addEventListener('click', () => {
                    if (typeof popup.hidePopover === 'function') {
                        popup.hidePopover();
                    }
                });
            });
        });
    };

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
    const openPreview = (button) => {
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
    const closePreview = () => {
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
     * The hint of a document that is open - it has to be put in place again when the page
     * is scrolled.
     *
     * @type {HTMLElement|null}
     */
    let openDocumentHint = null;

    /**
     * The popups with a button that opens them, so that they can be put in place again
     * when the page is scrolled.
     *
     * @type {Array<{popup: HTMLElement, trigger: HTMLElement}>}
     */
    const popupsWithTrigger = [];

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
    const setupPreviewScrollSync = () => {
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
    const togglePreviewScrollSync = () => {
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

    /**
     * The buttons of the review that are not changes themselves: the preview of the document
     * and the dialog of the preview.
     */
    document.body.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        const previewButton = target.closest('#document-preview-button');
        if (previewButton) {
            if (!previewButton.disabled) {
                openPreview(previewButton);
            }
            return;
        }

        if (target.closest('[data-role="preview-close"]')) {
            closePreview();
        }
    });

    // The popups of the review do not move with the page - they have to be put in place
    // again when it is scrolled.
    document.addEventListener(
        'scroll',
        () => {
            if (openDocumentHint) {
                const row = openDocumentHint.closest('.workspace-review-document-row');
                if (row) {
                    positionPopup(openDocumentHint, row, 'below', 'right');
                }
            }
            popupsWithTrigger.forEach(({ popup, trigger }) => {
                if (popup.matches(':popover-open')) {
                    positionPopup(popup, trigger, popup.getAttribute('data-placement') === 'below' ? 'below' : 'above');
                }
            });
        },
        true,
    );

    document.body.addEventListener('change', (event) => {
        const checkbox = event.target;
        if (!(checkbox instanceof HTMLInputElement)) {
            return;
        }

        if (checkbox.matches('.workspace-review-document-select')) {
            const row = checkbox.closest('.workspace-review-document-row');
            const contextPath = row ? row.getAttribute('data-document') || '' : '';
            if (contextPath === '') {
                return;
            }
            if (checkbox.checked) {
                selectDocument(contextPath);
            } else {
                deselectDocument(contextPath);
            }
            updateSelection();

            return;
        }

        // the scroll sync of the preview
        if (checkbox.matches('[data-role="preview-sync"]')) {
            togglePreviewScrollSync();

            return;
        }

        if (checkbox.matches('#document-changes .neos-content-change input[type="checkbox"]')) {
            if (checkbox.checked) {
                selectWithItsParents(checkbox);
            } else {
                deselectWithItsChildren(checkbox);
            }
            applyChangesOfOpenDocument();
            updateSelection();
        }
    });

    // the hint of a document tells what changed in it - the whole row opens it
    document.body.addEventListener('mouseover', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const row = target.closest('.workspace-review-document-row');
        if (!row) {
            return;
        }
        showDocumentHint(row);
    });

    document.body.addEventListener('mouseout', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const row = target.closest('.workspace-review-document-row');
        if (!row) {
            return;
        }
        const related = event.relatedTarget;
        if (related instanceof Node && row.contains(related)) {
            // the pointer is still inside the row
            return;
        }
        hideDocumentHint(row);
    });

    htmx.on('htmx:afterSwap', () => {
        setupPopovers();
        updateSelection();
    });

    setupPopovers();
    setupPreviewScrollSync();
    updateSelection();
});
