import { changesCountOfDocument } from './DocumentList.js';
import { positionPopup } from './Popups.js';
import { isCoveredBySelection, selectedChangesOfDocument } from './Selection.js';

/**
 * The hint of a document: what changed in it and how much of it is selected.
 */
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
 * The hint of a document that is open - it has to be put in place again when the page
 * is scrolled.
 *
 * @type {HTMLElement|null}
 */
let openDocumentHint = null;

/**
 * Shows the hint of a document, below its row.
 *
 * @param {HTMLElement} row
 */
export const showDocumentHint = (row) => {
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
export const hideDocumentHint = (row) => {
    const hint = row.querySelector('.workspace-review-document-hint');
    if (hint && typeof hint.hidePopover === 'function' && hint.matches(':popover-open')) {
        hint.hidePopover();
    }
    if (openDocumentHint === hint) {
        openDocumentHint = null;
    }
};

/**
 * Puts the hint that is open in place again - the popups of the browser do not move with
 * the page.
 */
export const repositionOpenHint = () => {
    if (!openDocumentHint) {
        return;
    }
    const row = openDocumentHint.closest('.workspace-review-document-row');
    if (row) {
        positionPopup(openDocumentHint, row, 'below', 'right');
    }
};
