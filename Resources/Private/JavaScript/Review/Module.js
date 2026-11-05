import { updateActionButtons } from './Actions.js';
import { readDocumentList } from './DocumentList.js';
import { hideDocumentHint, repositionOpenHint, showDocumentHint } from './Hints.js';
import { setupNotifications } from './Notifications.js';
import { repositionOpenPopups, setupPopovers } from './Popups.js';
import { closePreview, openPreview, setupPreviewScrollSync, togglePreviewScrollSync } from './Preview.js';
import {
    applyChangesOfOpenDocument,
    clearSelection,
    deselectDocument,
    deselectWithItsChildren,
    pruneSelection,
    restoreSelectionInChanges,
    selectDocument,
    selectWithItsParents,
    updateDocumentRows,
    updateSelectionInputs,
} from './Selection.js';

/**
 * The review of a workspace.
 *
 * The parts of the review live in their own modules - the list of the documents, the
 * selection of the changes, the hints, the popups, the actions and the preview. This file
 * wires them together: it listens to the events of the page and calls the parts.
 */
document.addEventListener('DOMContentLoaded', () => {
    if (!window.htmx) {
        console.error('htmx is not loaded');
        return;
    }

    setupNotifications();

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
     * The buttons of the review that are not changes themselves: the button that resets the
     * selection, the preview of the document and the dialog of the preview.
     */
    document.body.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        const resetButton = target.closest('#resetSelectionButton');
        if (resetButton) {
            if (!resetButton.disabled) {
                clearSelection();
                updateSelection();
            }
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
            repositionOpenHint();
            repositionOpenPopups();
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
