import { useCallback, useRef, useState } from 'react';
import { Button } from '@atlas-pos/ui';
import { uploadImage } from './api';
import { useToast } from '../../components/toastContext';

const MAX_SIZE_BYTES = 3 * 1024 * 1024;
const ACCEPTED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

type ImageUploaderProps = {
  value: string | null;
  onChange: (url: string | null) => void;
  label?: string;
};

export function ImageUploader({ value, onChange, label = 'Product image' }: ImageUploaderProps) {
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const { addToast } = useToast();
  const [isDragging, setIsDragging] = useState(false);
  const [isUploading, setIsUploading] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);

  const openFilePicker = () => {
    fileInputRef.current?.click();
  };

  const resetProgress = () => {
    setProgress(null);
    setIsUploading(false);
  };

  const handleFile = useCallback(
    async (file: File) => {
      if (!ACCEPTED_TYPES.includes(file.type)) {
        addToast({ type: 'error', message: 'Only JPG, JPEG, PNG, or WEBP images are allowed.' });
        return;
      }

      if (file.size > MAX_SIZE_BYTES) {
        addToast({ type: 'error', message: 'Image must be 3MB or smaller.' });
        return;
      }

      setIsUploading(true);

      try {
        const url = await uploadImage(file, (percent) => setProgress(percent));
        onChange(url);
        addToast({ type: 'success', message: 'Image uploaded.' });
      } catch (error) {
        addToast({ type: 'error', message: error instanceof Error ? error.message : 'Upload failed.' });
      } finally {
        resetProgress();
      }
    },
    [addToast, onChange]
  );

  const handleInputChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (file) {
      void handleFile(file);
    }
  };

  const handleDragOver = (event: React.DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    setIsDragging(true);
  };

  const handleDragLeave = () => {
    setIsDragging(false);
  };

  const handleDrop = (event: React.DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    setIsDragging(false);

    const file = event.dataTransfer.files?.[0];
    if (file) {
      void handleFile(file);
    }
  };

  const handleRemove = () => {
    onChange(null);
  };

  return (
    <div className="space-y-3">
      <label className="block text-sm font-medium text-slate-700">{label}</label>
      <div
        className={`flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed px-6 py-8 text-center transition ${
          isDragging ? 'border-blue-400 bg-blue-50/40' : 'border-slate-300'
        } ${isUploading ? 'opacity-70' : ''}`}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
        role="button"
        tabIndex={0}
        onClick={openFilePicker}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openFilePicker();
          }
        }}
      >
        {value ? (
          <img
            src={value}
            alt="Product"
            className="h-24 w-24 rounded-md object-cover shadow-sm"
          />
        ) : (
          <svg
            className="h-12 w-12 text-slate-300"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth="1.5"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M12 4.5v15m7.5-7.5h-15"
            />
          </svg>
        )}
        <div className="space-y-1">
          <p className="text-sm font-semibold text-slate-700">Drag & drop an image</p>
          <p className="text-xs text-slate-500">JPG, PNG, or WEBP up to 3MB</p>
        </div>
        <Button type="button" variant="secondary" size="sm" onClick={openFilePicker} disabled={isUploading}>
          {isUploading ? 'Uploading...' : 'Choose file'}
        </Button>
        {isUploading && progress !== null ? (
          <div className="w-full max-w-xs">
            <div className="h-2 rounded-full bg-slate-200">
              <div
                className="h-2 rounded-full bg-blue-500 transition-all"
                style={{ width: `${progress}%` }}
              />
            </div>
            <p className="mt-1 text-xs text-slate-500">{progress}%</p>
          </div>
        ) : null}
      </div>
      {value ? (
        <div className="flex justify-end">
          <Button type="button" variant="outline" size="sm" className="text-red-600" onClick={handleRemove}>
            Remove image
          </Button>
        </div>
      ) : null}
      <input
        ref={fileInputRef}
        type="file"
        accept={ACCEPTED_TYPES.join(',')}
        className="hidden"
        onChange={handleInputChange}
      />
    </div>
  );
}





