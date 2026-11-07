import { openDocument, selectedChangesCount } from './Selection.js';

/**
 * The buttons that publish and discard the changes.
 */
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

        // one change and more than one change have their own wording
        const template = hasSelection
            ? selectionCount === 1
                ? button.getAttribute('data-label-with-one-selection')
                : button.getAttribute('data-label-with-selection')
            : button.getAttribute('data-label');

        const label = button.querySelector('.workspace-review-combo-label');
        if (label) {
            label.innerHTML = (template || '').replace('{0}', String(selectionCount));
        }

        // The title of the button says what it does - the label says it in short.
        const title = hasSelection
            ? selectionCount === 1
                ? button.getAttribute('data-title-with-one-selection')
                : button.getAttribute('data-title-with-selection')
            : button.getAttribute('data-title');
        button.setAttribute('title', (title || '').replace('{0}', String(selectionCount)));

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
 * The button that resets the selection is only enabled when there is a selection to reset.
 *
 * The changes of a document are swapped into the page, so the state is updated after
 * every answer as well.
 */
export const updateActionButtons = () => {
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

    const resetButton = document.getElementById('resetSelectionButton');
    if (resetButton) {
        resetButton.disabled = selectionCount === 0;
    }
};
