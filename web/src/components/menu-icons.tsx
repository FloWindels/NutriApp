type IconName =
  | "recipe"
  | "family"
  | "sport"
  | "profile"
  | "settings"
  | "search"
  | "fridge"
  | "history"
  | "recommendation"
  | "planner"
  | "shopping"
  | "diet";

type MenuIconProps = {
  name: IconName;
  className?: string;
  strokeWidth?: number;
};

export type { IconName };

export default function MenuIcon({ name, className = "h-4 w-4", strokeWidth = 1.8 }: MenuIconProps) {
  const common = {
    fill: "none",
    stroke: "currentColor",
    strokeWidth,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
  };

  if (name === "recipe") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <circle {...common} cx="11" cy="12" r="6" />
        <path {...common} d="M18 7V17M16.5 9.5H19.5M16.5 14.5H19.5" />
        <path {...common} d="M4 7V17" />
      </svg>
    );
  }

  if (name === "family") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <circle {...common} cx="8" cy="8" r="2.5" />
        <circle {...common} cx="16" cy="8" r="2.5" />
        <path {...common} d="M3.5 18C3.9 15.6 5.8 14 8 14C10.2 14 12.1 15.6 12.5 18" />
        <path {...common} d="M11.5 18C11.9 15.6 13.8 14 16 14C18.2 14 20.1 15.6 20.5 18" />
      </svg>
    );
  }

  if (name === "sport") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <path {...common} d="M6 10L9 7M15 17L18 14M4.5 12.5L7.5 9.5M16.5 14.5L19.5 11.5" />
        <path {...common} d="M8 8L16 16" />
        <rect {...common} x="2" y="10" width="3" height="4" rx="1" />
        <rect {...common} x="19" y="10" width="3" height="4" rx="1" />
      </svg>
    );
  }

  if (name === "profile") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <circle {...common} cx="12" cy="8" r="3" />
        <path {...common} d="M5 19C5.7 15.9 8.5 14 12 14C15.5 14 18.3 15.9 19 19" />
      </svg>
    );
  }

  if (name === "settings") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <path
          {...common}
          d="M12 8.5A3.5 3.5 0 1 1 12 15.5A3.5 3.5 0 1 1 12 8.5Z M19.3 15L21 16L19 19L17.1 17.9C16.6 18.3 16 18.6 15.4 18.8L15 21H9L8.6 18.8C8 18.6 7.4 18.3 6.9 17.9L5 19L3 16L4.7 15C4.6 14.7 4.5 14.4 4.5 14C4.5 13.6 4.6 13.3 4.7 13L3 12L5 9L6.9 10.1C7.4 9.7 8 9.4 8.6 9.2L9 7H15L15.4 9.2C16 9.4 16.6 9.7 17.1 10.1L19 9L21 12L19.3 13C19.4 13.3 19.5 13.6 19.5 14C19.5 14.4 19.4 14.7 19.3 15Z"
        />
      </svg>
    );
  }

  if (name === "search") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <circle {...common} cx="11" cy="11" r="5" />
        <path {...common} d="M15 15L20 20" />
      </svg>
    );
  }

  if (name === "fridge") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <rect {...common} x="6" y="3" width="12" height="18" rx="2" />
        <path {...common} d="M6 11H18" />
        <path {...common} d="M9 7V9M9 14V16" />
      </svg>
    );
  }

  if (name === "history") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <path {...common} d="M4 12A8 8 0 1 0 6.3 6.3" />
        <path {...common} d="M4 4V9H9" />
        <path {...common} d="M12 8V12L15 14" />
      </svg>
    );
  }

  if (name === "recommendation") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <path {...common} d="M12 3L14.5 8.5L20.5 9.2L16 13.2L17.2 19L12 16L6.8 19L8 13.2L3.5 9.2L9.5 8.5Z" />
      </svg>
    );
  }

  if (name === "planner") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <rect {...common} x="4" y="5" width="16" height="15" rx="2" />
        <path {...common} d="M8 3V7M16 3V7M4 10H20" />
      </svg>
    );
  }

  if (name === "shopping") {
    return (
      <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
        <path {...common} d="M6 8H19L17.8 17H8.2Z" />
        <path {...common} d="M6 8L5 5H3" />
        <circle {...common} cx="9" cy="19" r="1" />
        <circle {...common} cx="16" cy="19" r="1" />
      </svg>
    );
  }

  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
      <path {...common} d="M4 12A8 8 0 1 0 20 12A8 8 0 1 0 4 12Z" />
      <path {...common} d="M9 12L11 14L15 10" />
    </svg>
  );
}
