const generateWorkspacesData = (): WorkspaceList => {
    return {
        'user-shelzle': {
            name: 'user-shelzle',
            title: 'Sebastian Helzle',
            description: 'This is my private workspace',
            owner: 'Sebastian Helzle',
            baseWorkspace: {
                name: 'live',
                title: 'live',
            },
            nodeCount: 23,
            changesCounts: null,
            isPersonal: true,
            isInternal: false,
            isStale: null,
            canPublish: true,
            canManage: false,
            dependentWorkspacesCount: 0,
            creator: 'shelzle',
            lastChangedDate: null,
            lastChangedTimestamp: 1652449543,
            lastChangedBy: 'shelzle',
        },
        'workspace-1': {
            name: 'workspace-1',
            title: 'Example workspace 1',
            description: 'This is a test workspace',
            owner: '',
            baseWorkspace: {
                name: 'live',
                title: 'live',
            },
            nodeCount: 1,
            changesCounts: null,
            isPersonal: true,
            isInternal: false,
            isStale: null,
            canPublish: true,
            canManage: false,
            dependentWorkspacesCount: 0,
            creator: 'shelzle',
            lastChangedDate: null,
            lastChangedTimestamp: 1652453494,
            lastChangedBy: 'shelzle',
        },
    };
};

function generateChangesByWorkspace() {
    return {
        'user-shelzle': {
            new: 10,
            changed: 5,
            removed: 8,
            total: 23,
        },
        'workspace-1': {
            new: 0,
            changed: 0,
            removed: 0,
            total: 0,
        },
    };
}

const loadFixtures = () => {
    return {
        workspaces: generateWorkspacesData(),
        changesByWorkspace: generateChangesByWorkspace(),
    };
};

export { loadFixtures };
