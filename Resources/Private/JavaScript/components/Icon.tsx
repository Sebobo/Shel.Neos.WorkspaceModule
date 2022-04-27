import * as React from 'react';

interface IconProps {
    icon: string;
    style?: React.CSSProperties;
    spin?: boolean;
    rotate?: number;
}

const Icon: React.FC<IconProps> = ({ icon, style, spin = false, rotate = 90 | 180 | 270 }) => {
    return (
        <i
            className={['fas', `fa-${icon}`, spin && 'fa-spin', rotate && `fa-rotate-${rotate}`].join(' ')}
            style={style}
        />
    );
};

export default React.memo(Icon);
