import React, { useEffect } from 'react';
import { useNotify } from '../provider/NotifyProvider';
import { useWorkspaces } from '../provider/WorkspaceProvider';
import { useIntl } from '../provider/IntlProvider';

const NAGGING_STORAGE_KEY = 'shel.neos.workspacemodule.lastNagging';
const NAGGING_TIMEOUT = 86400; // 24 hours in seconds

const NaggingScreen: React.FC = () => {
    const notify = useNotify();
    const { translate } = useIntl();
    const { username, workspaces } = useWorkspaces();

    useEffect(() => {
        // Check if the user owns stale workspaces and nag them about deleting them, but only once per day by setting a cookie
        const lastNagging = localStorage.getItem(NAGGING_STORAGE_KEY);
        if (!lastNagging || Date.now() - parseInt(lastNagging, 10) > NAGGING_TIMEOUT) {
            const staleWorkspaceNamesOwnedByCurrentUser = Object.keys(workspaces).filter((workspaceName) => {
                const workspace = workspaces[workspaceName];
                return (
                    !workspace.isPersonal &&
                    workspace.isStale &&
                    (workspace.owner?.name === username || workspace.creator?.name === username)
                );
            });

            if (staleWorkspaceNamesOwnedByCurrentUser.length > 0) {
                const message = translate(
                    'nagging.staleWorkspacesWarning',
                    'You own {0} stale workspaces. Please consider deleting them.',
                    [staleWorkspaceNamesOwnedByCurrentUser.length],
                );
                notify.warning(
                    message,
                    staleWorkspaceNamesOwnedByCurrentUser
                        .map((workspaceName) => workspaces[workspaceName].title)
                        .join(', '),
                );
            }

            localStorage.setItem(NAGGING_STORAGE_KEY, Date.now().toString());
        }
    }, []);

    return null;
};

export default NaggingScreen;
