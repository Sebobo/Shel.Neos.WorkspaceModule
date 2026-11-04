import React, { useCallback, useMemo } from 'react';

import { useWorkspaces } from '../../provider/WorkspaceProvider';
import { DialogHeader, StyledModal, ActionBar } from './StyledModal';
import { useIntl } from '../../provider/IntlProvider';

const PruneWorkspaceDialog: React.FC = () => {
    const { selectedWorkspaceForPruning, setSelectedWorkspaceForPruning, pruneWorkspace, workspaces } = useWorkspaces();
    const { translate } = useIntl();

    const selectedWorkspace = useMemo(() => workspaces[selectedWorkspaceForPruning], [selectedWorkspaceForPruning]);

    const handleClose = useCallback(() => {
        setSelectedWorkspaceForPruning(null);
    }, []);

    const handlePrune = useCallback(() => {
        pruneWorkspace(selectedWorkspaceForPruning);
        handleClose();
    }, [selectedWorkspaceForPruning]);

    return selectedWorkspaceForPruning && selectedWorkspace.nodeCount > 1 ? (
        <StyledModal isOpen onRequestClose={handleClose}>
            <DialogHeader>
                {translate('dialog.prune.header', `Prune "${selectedWorkspace.title}"?`, {
                    workspace: selectedWorkspace.title,
                })}
            </DialogHeader>

            <p
                dangerouslySetInnerHTML={{
                    __html: translate(
                        'dialog.prune.unpublishedChanges',
                        `Pruning this workspace will discard ${selectedWorkspace.nodeCount - 1} changes.`,
                        { count: selectedWorkspace.nodeCount - 1 },
                    ),
                }}
            />
            <p>{translate('dialog.prune.pointOfNoReturn', 'This action cannot be undone.')}</p>

            <ActionBar>
                <button type="button" className="neos-button" onClick={handleClose}>
                    {translate('dialog.action.cancel', 'Cancel')}
                </button>
                <button type="button" className="neos-button neos-button-danger" onClick={handlePrune}>
                    {translate('dialog.action.prune', 'Prune')}
                </button>
            </ActionBar>
        </StyledModal>
    ) : null;
};

export default React.memo(PruneWorkspaceDialog);
