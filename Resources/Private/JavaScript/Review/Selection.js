import { changesCountOfDocument, documentRow, documentRows, documentsBelow } from './DocumentList.js';

/**
 * The selection of the review: the documents and the changes that are selected.
 *
 * A selection holds single changes and whole documents. A document stands for all of its
 * changes - and for the documents below it, which are selected with it.
 *
 * The selection lives in the browser, because the changes of a document are swapped into
 * the page: it survives the change of the document that is open.
 */

/**
 * @typedef {Object} ChangeSelection
 * @property {string} document the document the change belongs to
 */

const selection = {
    /** @type {Set<string>} */
    documents: new Set(),
    /** @type {Map<string, ChangeSelection>} */
    changes: new Map(),
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
export const isCoveredBySelection = (contextPath) => {
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
export const selectedChangesOfDocument = (contextPath) => {
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
export const selectDocument = (contextPath) => {
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
export const deselectDocument = (contextPath) => {
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
 * Takes everything out of the selection.
 */
export const clearSelection = () => {
    selection.documents.clear();
    selection.changes.clear();
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
export const selectWithItsParents = (checkbox) => {
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
export const deselectWithItsChildren = (checkbox) => {
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
export const openDocument = () => {
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
export const applyChangesOfOpenDocument = () => {
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
export const restoreSelectionInChanges = () => {
    const document = openDocument();
    const covered = document !== '' && isCoveredBySelection(document);
    changeCheckboxes().forEach((checkbox) => {
        checkbox.checked = covered || selection.changes.has(checkbox.value);
    });
};

/**
 * Removes the parts of the selection that are not there any more.
 */
export const pruneSelection = () => {
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
export const selectedChangesCount = () => {
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
export const updateSelectionInputs = () => {
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
export const updateDocumentRows = () => {
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
