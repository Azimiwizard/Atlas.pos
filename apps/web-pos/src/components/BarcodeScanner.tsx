import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useRef,
  useState,
} from 'react';

type BarcodeDetectorResult = {
  rawValue?: string;
};

type BarcodeDetectorCtor = new (options?: { formats?: string[] }) => {
  detect(source: CanvasImageSource): Promise<BarcodeDetectorResult[]>;
};

type BarcodeScannerProps = {
  onDetected: (value: string) => void;
  onError?: (message: string) => void;
  className?: string;
};

export type BarcodeScannerHandle = {
  start: () => Promise<void>;
  stop: () => void;
  isRunning: () => boolean;
};

const SUPPORTED_FORMATS: string[] = [
  'code_128',
  'qr_code',
  'ean_13',
  'ean_8',
  'upc_a',
  'upc_e',
];

const getDetectorCtor = (): BarcodeDetectorCtor | null => {
  const global = globalThis as Record<string, unknown>;
  if (typeof global.BarcodeDetector === 'function') {
    return global.BarcodeDetector as BarcodeDetectorCtor;
  }
  return null;
};

export const BarcodeScanner = forwardRef<BarcodeScannerHandle, BarcodeScannerProps>(
  ({ onDetected, onError, className }, ref) => {
    const [status, setStatus] = useState<'idle' | 'initializing' | 'running' | 'unsupported'>(
      getDetectorCtor() ? 'idle' : 'unsupported'
    );
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const detectorRef = useRef<InstanceType<BarcodeDetectorCtor> | null>(null);
    const rafRef = useRef<number | null>(null);
    const runningRef = useRef(false);
    const lastDetectionRef = useRef<string | null>(null);

    const cleanup = useCallback(() => {
      if (rafRef.current !== null) {
        cancelAnimationFrame(rafRef.current);
        rafRef.current = null;
      }

      const tracks = streamRef.current?.getTracks() ?? [];
      tracks.forEach((track) => track.stop());
      streamRef.current = null;

      if (videoRef.current) {
        videoRef.current.srcObject = null;
      }

      detectorRef.current = null;
      runningRef.current = false;
      setStatus((prev) => (prev === 'unsupported' ? prev : 'idle'));
    }, []);

    const emitError = useCallback(
      (message: string) => {
        setErrorMessage(message);
        onError?.(message);
      },
      [onError]
    );

    const handleDetection = useCallback(
      (raw: string | undefined | null) => {
        const value = raw?.trim();
        if (!value) {
          return;
        }

        if (value === lastDetectionRef.current) {
          return;
        }

        lastDetectionRef.current = value;
        onDetected(value);
      },
      [onDetected]
    );

    const scanFrame = useCallback(async () => {
      if (!runningRef.current || !videoRef.current || !detectorRef.current) {
        return;
      }

      try {
        const detections = await detectorRef.current.detect(videoRef.current);
        const first = detections.find((item) => item.rawValue);
        if (first?.rawValue) {
          handleDetection(first.rawValue);
        }
      } catch (error) {
        emitError(
          error instanceof Error ? error.message : 'Unable to read barcode from camera stream.'
        );
        runningRef.current = false;
        setStatus('idle');
        return;
      }

      rafRef.current = requestAnimationFrame(() => {
        void scanFrame();
      });
    }, [emitError, handleDetection]);

    const start = useCallback(async () => {
      if (status === 'initializing' || status === 'running') {
        return;
      }

      const BarcodeDetectorCtor = getDetectorCtor();
      if (!BarcodeDetectorCtor) {
        setStatus('unsupported');
        emitError('Barcode scanning is not supported on this device.');
        return;
      }

      if (!navigator.mediaDevices?.getUserMedia) {
        emitError('Camera access is not available in this browser.');
        setStatus('unsupported');
        return;
      }

      setErrorMessage(null);
      setStatus('initializing');
      lastDetectionRef.current = null;

      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment' },
          audio: false,
        });

        streamRef.current = stream;

        detectorRef.current = new BarcodeDetectorCtor({
          formats: SUPPORTED_FORMATS,
        });

        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          await videoRef.current.play();
        }

        runningRef.current = true;
        setStatus('running');
        rafRef.current = requestAnimationFrame(() => {
          void scanFrame();
        });
      } catch (error) {
        cleanup();
        const message =
          error instanceof Error ? error.message : 'Unable to start barcode scanner.';
        emitError(message);
        throw error;
      }
    }, [cleanup, emitError, scanFrame, status]);

    const stop = useCallback(() => {
      cleanup();
    }, [cleanup]);

    useImperativeHandle(
      ref,
      () => ({
        start,
        stop,
        isRunning: () => runningRef.current,
      }),
      [start, stop]
    );

    useEffect(() => {
      return () => {
        stop();
      };
    }, [stop]);

    return (
      <div
        className={`flex min-h-[16rem] flex-col items-center justify-center rounded-xl border border-[color:var(--pos-border)] bg-[color:var(--pos-surface)] p-4 text-center text-sm text-[color:var(--pos-text-muted)] ${
          className ?? ''
        }`}
      >
        {status === 'unsupported' ? (
          <p>
            Camera scanning isn&apos;t supported on this device. Use the search field or manual
            entry instead.
          </p>
        ) : (
          <>
            <video
              ref={videoRef}
              className="mb-3 h-48 w-full rounded-lg object-cover"
              playsInline
              muted
            />
            <div className="text-xs font-medium uppercase tracking-wide text-[color:var(--pos-text-muted)]">
              {status === 'initializing'
                ? 'Initializing camera...'
                : status === 'running'
                  ? 'Scanning for barcodes...'
                  : 'Tap to start scanning'}
            </div>
            {errorMessage ? (
              <p className="mt-2 max-w-sm text-xs text-red-500">{errorMessage}</p>
            ) : null}
          </>
        )}
      </div>
    );
  }
);

BarcodeScanner.displayName = 'BarcodeScanner';
