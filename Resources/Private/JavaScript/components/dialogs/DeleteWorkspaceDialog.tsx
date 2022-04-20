import React, { useCallback } from 'react';
import ReactModal from 'react-modal';

import { useWorkspaces } from '../../provider/WorkspaceProvider';

const DeleteWorkspaceDialog: React.FC = () => {
    const { selectedWorkspaceForDeletion, setSelectedWorkspaceForDeletion, deleteWorkspace } = useWorkspaces();

    const handleClose = useCallback(() => {
        setSelectedWorkspaceForDeletion(null);
    }, []);

    return <ReactModal isOpen={!!selectedWorkspaceForDeletion} onRequestClose={handleClose}>
        <h2>Delete {selectedWorkspaceForDeletion}?</h2>

        <button
            type="button"
            className="neos-button"
            onClick={handleClose}
        >
            Cancel
        </button>
        <button
            type="button"
            className="neos-button"
        >
            Delete this workspace
        </button>
    </ReactModal>;
}

export default React.memo(DeleteWorkspaceDialog);
