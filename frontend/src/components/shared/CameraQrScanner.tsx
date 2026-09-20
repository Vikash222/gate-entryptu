import React, { useEffect, useRef, useState } from 'react';
import { Html5Qrcode } from 'html5-qrcode';
import { Camera, CameraOff, AlertCircle } from 'lucide-react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';

interface CameraQrScannerProps {
  isOpen: boolean;
  onClose: () => void;
  onScanSuccess: (decodedText: string) => void;
  title?: string;
  description?: string;
}

export const CameraQrScanner: React.FC<CameraQrScannerProps> = ({
  isOpen,
  onClose,
  onScanSuccess,
  title = 'Scan Gate QR',
  description = 'Point your camera at the temporary QR code displayed on the Security Guard screen',
}) => {
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [hasPermissionError, setHasPermissionError] = useState(false);
  const scannerRef = useRef<Html5Qrcode | null>(null);
  const isScanningRef = useRef(false);

  useEffect(() => {
    let html5QrCode: Html5Qrcode | null = null;
    let isMounted = true;

    if (isOpen) {
      setErrorMessage(null);
      setHasPermissionError(false);

      const startScanner = async () => {
        try {
          // 1. Explicitly check for secure context (HTTPS/localhost)
          if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('SECURE_CONTEXT_REQUIRED');
          }

          // 2. Pre-request permissions explicitly to trigger browser prompt
          // We try environment first, but fallback to any video if it fails
          let stream;
          try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
          } catch (e: any) {
            if (e.name === 'OverconstrainedError' || e.name === 'NotFoundError') {
              // Fallback for devices without a rear camera (like laptops)
              stream = await navigator.mediaDevices.getUserMedia({ video: true });
            } else {
              throw e;
            }
          }
          
          // Stop the test stream, we just needed the permission granted
          stream.getTracks().forEach(track => track.stop());

          if (!isMounted) return;

          // Give DOM a moment to mount container element
          await new Promise((resolve) => setTimeout(resolve, 300));
          const container = document.getElementById('qr-camera-stream');
          if (!container) return;

          html5QrCode = new Html5Qrcode('qr-camera-stream');
          scannerRef.current = html5QrCode;

          const config = {
            fps: 10,
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0,
          };

          // Use getCameras to reliably start the scanner with an available device
          const devices = await Html5Qrcode.getCameras();
          if (devices && devices.length > 0) {
            // Try to find the back camera, otherwise just use the first available
            let cameraId = devices[0].id;
            const backCamera = devices.find(d => d.label.toLowerCase().includes('back') || d.label.toLowerCase().includes('environment'));
            if (backCamera) {
              cameraId = backCamera.id;
            }

            await html5QrCode.start(
              cameraId,
              config,
              (decodedText) => {
                if (isScanningRef.current) return;
                isScanningRef.current = true;

                // Play gentle haptic vibration if supported
                if ('vibrate' in navigator) {
                  navigator.vibrate(50);
                }

                // Stop scanner immediately upon detection
                html5QrCode?.stop().then(() => {
                  onScanSuccess(decodedText);
                }).catch(() => {
                  onScanSuccess(decodedText);
                });
              },
              () => {
                // Ignore frame errors quietly
              }
            );
          } else {
             throw new Error('No cameras found on this device');
          }

        } catch (err: any) {
          console.error('Camera initialization error:', err);
          if (!isMounted) return;

          if (err.message === 'SECURE_CONTEXT_REQUIRED') {
             setErrorMessage('Camera access requires a secure connection. Ensure you are using HTTPS or localhost.');
          } else if (err?.name === 'NotAllowedError' || err?.message?.includes('Permission denied')) {
            setHasPermissionError(true);
            setErrorMessage('Camera permission is required to verify the gate. Please allow camera access in your browser settings and try again.');
          } else {
            setErrorMessage(`Camera stream could not be started: ${err?.message || 'Ensure no other app is using the camera.'}`);
          }
        }
      };

      startScanner();
    }

    return () => {
      isMounted = false;
      isScanningRef.current = false;
      if (scannerRef.current) {
        scannerRef.current
          .stop()
          .catch(() => {})
          .finally(() => {
            scannerRef.current?.clear();
          });
      }
    };
  }, [isOpen, onScanSuccess]);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={title} description={description} size="md">
      <div className="flex flex-col items-center space-y-4 pt-2">
        {hasPermissionError ? (
          <div className="p-6 text-center space-y-3 bg-rose-50 rounded-2xl border border-rose-200">
            <div className="inline-flex p-3 bg-rose-100 text-rose-600 rounded-full">
              <CameraOff className="h-8 w-8" />
            </div>
            <h4 className="font-bold text-slate-900 text-sm">Camera Permission Denied</h4>
            <p className="text-xs text-slate-600 leading-relaxed">
              Camera permission is required to verify the gate. Please allow camera access and try again.
            </p>
            <Button onClick={() => window.location.reload()} variant="outline" className="w-full text-xs">
              Reload & Request Camera
            </Button>
          </div>
        ) : errorMessage ? (
          <div className="p-4 bg-amber-50 rounded-2xl border border-amber-200 text-left flex gap-3">
            <AlertCircle className="h-5 w-5 text-amber-600 flex-shrink-0" />
            <p className="text-xs text-amber-900">{errorMessage}</p>
          </div>
        ) : (
          <div className="relative w-full max-w-[320px] aspect-square rounded-2xl overflow-hidden bg-slate-950 flex items-center justify-center border-2 border-blue-500 shadow-xl">
            <div id="qr-camera-stream" className="w-full h-full" />
            {/* Viewfinder overlay guides */}
            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-between p-6">
              <div className="w-full flex justify-between">
                <div className="w-8 h-8 border-t-4 border-l-4 border-blue-400 rounded-tl-lg" />
                <div className="w-8 h-8 border-t-4 border-r-4 border-blue-400 rounded-tr-lg" />
              </div>
              {/* Animated scanline */}
              <div className="w-full h-0.5 bg-blue-400 shadow-md shadow-blue-400 animate-scan" />
              <div className="w-full flex justify-between">
                <div className="w-8 h-8 border-b-4 border-l-4 border-blue-400 rounded-bl-lg" />
                <div className="w-8 h-8 border-b-4 border-r-4 border-blue-400 rounded-br-lg" />
              </div>
            </div>
          </div>
        )}

        <div className="flex items-center gap-2 text-xs text-slate-400 font-medium">
          <Camera className="h-4 w-4 text-blue-500" />
          <span>Scanning physical gate screen</span>
        </div>

        <Button variant="ghost" onClick={onClose} className="w-full text-xs">
          Cancel
        </Button>
      </div>
    </Modal>
  );
};
