import express from 'express';
import * as path from 'path';

import { loadFixtures } from './fixtures';

// This file is bundled to "Tests/dev-server/dist/server.js", so all paths are relative to that directory
const bundleDirectory = __dirname;
const devServerDirectory = path.join(bundleDirectory, '..');
const packageDirectory = path.join(bundleDirectory, '../../..');

const { workspaces, changesByWorkspace, baseWorkspaceOptions } = loadFixtures();

// Extract a single value of a multipart form without pulling in a full multipart parser
const extractFormValue = (body: string, fieldName: string): string | undefined => {
    if (typeof body !== 'string') {
        return undefined;
    }

    const escapedFieldName = fieldName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = body.match(new RegExp(`name="${escapedFieldName}"\\r\\n\\r\\n([^\\r\\n]*)`));

    return match?.[1];
};

// Setup express server
const port = 4000;
const app = express();

app.use(express.json());
app.use(express.text({ type: 'multipart/form-data' }));
app.use(express.static(path.join(devServerDirectory, 'public')));
app.use(express.static(bundleDirectory));
app.use(express.static(path.join(packageDirectory, 'Resources/Public')));

app.get('/', (req, res) => {
    res.sendFile(path.join(devServerDirectory, 'index.html'));
});

app.get('/getChanges', (req, res) => {
    res.json({
        changesByWorkspace,
    });
});

app.get('/showWorkspace', (req, res) => {
    const workspaceName = req.query.workspace.toString();
    const workspace = workspaces[workspaceName];
    res.send(`Viewing changes in workspace "${workspace.title}" is not implemented yet!`);
});

app.post('/createWorkspace', (req, res) => {
    const title = extractFormValue(req.body, 'moduleArguments[title]') ?? 'New workspace';
    const description = extractFormValue(req.body, 'moduleArguments[description]') ?? '';
    const name = `workspace-${Date.now()}`;

    res.json({
        success: true,
        workspace: {
            name,
            title,
            description,
            owner: null,
            baseWorkspace: {
                name: 'live',
                title: 'Live',
            },
            nodeCount: 0,
            changesCounts: {
                new: 0,
                changed: 0,
                removed: 0,
                total: 0,
            },
            isPersonal: false,
            isInternal: true,
            isStale: false,
            canPublish: true,
            canManage: true,
            dependentWorkspacesCount: 0,
            creator: null,
            lastChangedDate: new Date().toISOString(),
            lastChangedTimestamp: Math.floor(Date.now() / 1000),
            lastChangedBy: null,
            acl: [],
        },
        messages: [],
        baseWorkspaceOptions: {
            ...baseWorkspaceOptions,
            [name]: title,
        },
    });
});

app.listen(port, () => {
    console.log(`Success! Your application is running on port ${port}.`);
});
