/**
 * The document that is open in the review.
 *
 * The document is named in the fragment of the url: the browser finds its way back to it when
 * the page is reloaded, and the back and the forward button walk through the documents that
 * were open. The fragment is never sent to the server - the list of the documents and the
 * changes of the document are loaded separately anyway.
 */
import { documentRow, documentRows } from './DocumentList.js';

/**
 * Whether the review is opening a document itself - the fragment of the url names that
 * document already, so it is not pushed onto the history of the browser.
 */
let opening = false;

/**
 * Whether a document has been opened in this page - the list of the documents is in the page
 * from then on, and the document of the fragment is opened once.
 */
let opened = false;

/**
 * The context path of the document that is open - the fragment of the url.
 *
 * @returns {string}
 */
export const openDocument = () => {
    const fragment = window.location.hash.replace(/^#/, '');
    return fragment === '' ? '' : decodeURIComponent(fragment);
};

/**
 * Remembers the document that is open in the fragment of the url.
 *
 * A document the user opened is pushed onto the history of the browser, so that the back and
 * the forward button walk through the documents that were open. A document the review opens
 * itself replaces the fragment.
 *
 * @param {string} contextPath
 * @param {boolean} push
 */
const rememberOpenDocument = (contextPath, push) => {
    const url = contextPath === '' ? window.location.pathname + window.location.search : '#' + contextPath;
    if (push) {
        window.history.pushState({ document: contextPath }, '', url);
    } else {
        window.history.replaceState({ document: contextPath }, '', url);
    }
};

/**
 * Marks the document that is open in the list of the documents.
 *
 * @param {string} contextPath
 */
const markOpenDocument = (contextPath) => {
    documentRows().forEach((row) => {
        row.classList.toggle('active', row.getAttribute('data-document') === contextPath);
    });
};

/**
 * Opens the document with the given context path - its changes are loaded.
 *
 * @param {string} contextPath
 * @returns {boolean} whether the document is in the list of the documents
 */
const openDocumentInReview = (contextPath) => {
    const row = documentRow(contextPath);
    if (!row) {
        return false;
    }
    const button = row.querySelector('.workspace-review-document');
    if (!button) {
        return false;
    }
    if (contextPath !== openDocument()) {
        rememberOpenDocument(contextPath, false);
    }
    opening = true;
    button.click();
    opening = false;

    return true;
};

/**
 * The document that is open when the url names none - the first one of the list.
 *
 * @returns {string}
 */
const firstDocument = () => {
    const row = documentRows()[0];

    return row ? row.getAttribute('data-document') || '' : '';
};

/**
 * Keeps the document that is open in the fragment of the url.
 *
 * The url is the place where the document that is open is kept: the browser finds its way
 * back to it when the page is reloaded, and the back and the forward button walk through the
 * documents that were opened.
 */
export const setupDocumentNavigation = () => {
    // The user opened a document - the fragment of the url follows it.
    htmx.on('htmx:beforeRequest', (event) => {
        if (opening) {
            return;
        }
        const row = event.detail.elt ? event.detail.elt.closest('.workspace-review-document-row') : null;
        if (!row) {
            return;
        }
        const contextPath = row.getAttribute('data-document') || '';
        if (contextPath !== openDocument()) {
            rememberOpenDocument(contextPath, true);
        }
    });

    htmx.on('htmx:afterSwap', (event) => {
        // The element the event was fired on is the content that was swapped in - the target
        // of the request is the element it replaced.
        const target = event.target;
        if (!target || target.id !== 'document-changes') {
            return;
        }
        // The changes of the document are in the page - the document is marked in the list.
        markOpenDocument(target.getAttribute('data-document') || '');
    });

    htmx.on('htmx:afterSettle', (event) => {
        const target = event.target;
        if (!target || target.id !== 'document-tree') {
            return;
        }
        // The list of the documents is in the page: the document that is open is marked, and
        // the document of the fragment is opened when the review opens.
        markOpenDocument(openDocument());
        if (!opened) {
            opened = true;
            openDocumentInReview(openDocument() || firstDocument());
        }
    });

    // The back and the forward button walk through the documents that were opened.
    window.addEventListener('popstate', () => {
        openDocumentInReview(openDocument());
    });
};
