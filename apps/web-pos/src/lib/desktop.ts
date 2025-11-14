export function getDesktopApi(): Window['atlasDesktop'] | undefined {
  if (typeof window === 'undefined') {
    return undefined;
  }

  return window.atlasDesktop;
}

export function isDesktopEnvironment(): boolean {
  return Boolean(getDesktopApi());
}
