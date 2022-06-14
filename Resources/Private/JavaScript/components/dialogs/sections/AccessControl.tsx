import React, { ChangeEvent, useCallback, useRef, useState } from 'react';

import { useWorkspaces } from '../../../provider/WorkspaceProvider';
import { FormGroup, Icon, RadioLabel } from '../../presentationals';

type SectionProps = {
    workspace?: Workspace;
};

const AccessControl: React.FC<SectionProps> = ({ workspace }) => {
    const { translate, userList, userCanManageInternalWorkspaces } = useWorkspaces();
    const ownerField = useRef<HTMLSelectElement>(null);

    const [owner, setOwner] = useState(workspace?.owner?.id);

    const updateOwner = useCallback((event: ChangeEvent<HTMLSelectElement>) => setOwner(event.target.value), []);

    // TODO: Allow setting an owner already during creation
    return workspace ? (
        <FormGroup>
            {!workspace.isPersonal && (
                <label>
                    {translate('workspace.owner.label', 'Owner')}
                    <select
                        name={'moduleArguments[workspace][owner]'}
                        disabled={!userCanManageInternalWorkspaces}
                        defaultValue={workspace?.owner?.id}
                        ref={ownerField}
                        onChange={updateOwner}
                    >
                        {Object.keys(userList).map((userId) => (
                            <option key={userId} value={userId}>
                                {userList[userId]}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <p>
                <Icon icon="info-circle" style={{ color: 'var(--blue)', marginRight: '.5em' }} />
                {workspace.isPersonal
                    ? translate('workspace.visibility.isPersonal', 'This workspace is personal')
                    : owner
                    ? translate('workspace.visibility.private.info', 'This workspace is private')
                    : translate('workspace.visibility.internal.info', 'This workspace is internal')}
            </p>
            {!workspace.isPersonal && owner && (
                <FormGroup>
                    <label>{translate('workspace.acl.label', 'Allow access for additional users:')}</label>
                    <select
                        name="moduleArguments[acl][]"
                        multiple={true}
                        defaultValue={workspace?.acl ? Object.values(workspace.acl).map((user) => user.id) : []}
                        size={Math.min(5, Object.keys(userList).length)}
                    >
                        {Object.keys(userList).map((userId) =>
                            userId && userId !== owner ? (
                                <option key={userId} value={userId}>
                                    {userList[userId]}
                                </option>
                            ) : (
                                ''
                            )
                        )}
                    </select>
                </FormGroup>
            )}
        </FormGroup>
    ) : (
        <FormGroup>
            <label className="neos-control-label">{translate('workspace.visibility.label', 'Visibility')}</label>
            <RadioLabel className="neos-radio">
                <input type="radio" name="moduleArguments[visibility]" defaultChecked value="internal" />
                <span />
                <span>{translate('workspace.visibility.internal', 'Internal')}</span>
            </RadioLabel>
            <RadioLabel className="neos-radio">
                <input type="radio" name="moduleArguments[visibility]" value="private" />
                <span />
                <span>{translate('workspace.visibility.private', 'Private')}</span>
            </RadioLabel>
        </FormGroup>
    );
};

export default React.memo(AccessControl);
