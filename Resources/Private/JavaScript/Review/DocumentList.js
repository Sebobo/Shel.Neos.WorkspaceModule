/**
 * The list of the documents of the workspace: its rows, the documents below a document and
 * the number of the changes of a document.
 */
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
export const documentRows = () => Array.from(document.querySelectorAll('.workspace-review-document-row'));

/**
 * The row of a document, addressed by its context path.
 *
 * @param {string} contextPath
 * @returns {HTMLElement|undefined}
 */
export const documentRow = (contextPath) =>
    documentRows().find((row) => row.getAttribute('data-document') === contextPath);

/**
 * The number of the changes of a document, as the list of the documents knows it.
 *
 * @param {string} contextPath
 * @returns {number}
 */
export const changesCountOfDocument = (contextPath) => {
    const row = documentRow(contextPath);

    return row ? parseInt(row.getAttribute('data-count') || '0', 10) : 0;
};

/**
 * Reads the documents of the list again, so that the documents below a document can be
 * found.
 */
export const readDocumentList = () => {
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
export const documentsBelow = (contextPath) => {
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
