import {
  createElement,
  useCallback,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { ToastContext, type ToastType, type ToastContextValue } from './toastContext';

type Toast = {
  id: number;
  type: ToastType;
  message: string;
};

const typeStyles: Record<ToastType, string> = {
  success: 'border-green-200 bg-green-50 text-green-700',
  error: 'border-red-200 bg-red-50 text-red-700',
  info: 'border-blue-200 bg-blue-50 text-blue-700',
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const timers = useRef<Record<number, ReturnType<typeof setTimeout>>>({});

  const dismissToast = useCallback((id: number) => {
    setToasts((previous) => previous.filter((toast) => toast.id !== id));
    if (timers.current[id]) {
      clearTimeout(timers.current[id]);
      delete timers.current[id];
    }
  }, []);

  const addToast = useCallback(
    ({ type, message, duration = 4000 }: { type: ToastType; message: string; duration?: number }) => {
      const id = Date.now() + Math.random();
      setToasts((previous) => [...previous, { id, type, message }]);

      timers.current[id] = setTimeout(() => {
        dismissToast(id);
      }, duration);
    },
    [dismissToast]
  );

  const value = useMemo<ToastContextValue>(() => ({ addToast }), [addToast]);

  return createElement(
    ToastContext.Provider,
    { value },
    <>
      {children}
      <div className="pointer-events-none fixed inset-x-0 top-4 z-50 flex justify-center px-4">
        <div className="flex w-full max-w-sm flex-col gap-3">
          {toasts.map((toast) => (
            <div
              key={toast.id}
              className={`pointer-events-auto rounded-lg border px-4 py-3 text-sm shadow-md transition-opacity ${typeStyles[toast.type]}`}
            >
              <div className="flex items-start justify-between gap-3">
                <span className="font-medium">{toast.message}</span>
                <button
                  type="button"
                  className="text-xs font-semibold uppercase tracking-wide text-slate-400"
                  onClick={() => dismissToast(toast.id)}
                >
                  Close
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </>
  );
}
