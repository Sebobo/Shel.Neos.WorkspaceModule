import * as React from 'react';

import WorkspaceTable from './WorkspaceTable';
import ErrorBoundary from './ErrorBoundary';

const WorkspaceModule: React.FC = () => {
    return (
        <ErrorBoundary>
            <WorkspaceTable />
        </ErrorBoundary>
    );
};

export default React.memo(WorkspaceModule);
