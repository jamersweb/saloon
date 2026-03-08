export default function ApplicationLogo({ className = '', ...props }) {
    return (
        <img
            src="/images/vina-logo.png"
            alt="Vina logo"
            className={className}
            {...props}
        />
    );
}
