import * as React from 'react';

interface IconProps {
    icon: string;
    style?: React.CSSProperties;
    spin?: boolean;
}

const Icon: React.FC<IconProps> = ({ icon, style, spin = false }) => {
    return <i className={['fas', `fa-${icon}`, spin && 'fa-spin'].join(' ')} style={style} />;
};

export default React.memo(Icon);
