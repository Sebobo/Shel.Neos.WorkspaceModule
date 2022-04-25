import React from 'react';
import { useWorkspaces } from '../provider/WorkspaceProvider';

const Footer: React.FC = () => {
    const { setCreationDialogVisible } = useWorkspaces();

    return (
        <div className="neos-footer">
            <button
                type="button"
                className="neos-button neos-button-success"
                onClick={() => setCreationDialogVisible(true)}
            >
                Create new workspace
            </button>
        </div>
    );
};

export default React.memo(Footer);
