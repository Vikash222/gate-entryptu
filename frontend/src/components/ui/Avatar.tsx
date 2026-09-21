import React, { useState, useEffect } from 'react';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export interface AvatarProps extends React.HTMLAttributes<HTMLDivElement> {
  src?: string | null;
  name?: string;
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl';
  shape?: 'circle' | 'rounded';
  alt?: string;
  badge?: 'inside' | 'outside' | 'warning' | null;
}

// In-memory cache for authenticated image blobs to prevent duplicate network calls across repeated list rows
const blobCache = new Map<string, string>();
const inFlightRequests = new Map<string, Promise<string | null>>();

/**
 * Fetch a private media photo using the Authorization: Bearer <token> header.
 * Converts the HTTP response to a Blob and creates an object URL for <img>.
 * Never exposes the token in the URL or <img> tag.
 */
export async function loadAuthenticatedPhotoBlob(url: string): Promise<string | null> {
  if (!url) return null;

  // If already a data URI or blob URL, return as-is
  if (url.startsWith('data:') || url.startsWith('blob:')) {
    return url;
  }

  // Return cached object URL if available
  if (blobCache.has(url)) {
    return blobCache.get(url)!;
  }

  // Deduplicate in-flight requests for the same image URL
  if (inFlightRequests.has(url)) {
    return inFlightRequests.get(url)!;
  }

  const promise = (async () => {
    try {
      const token = localStorage.getItem('smartgate_token');
      const response = await fetch(url, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });

      if (!response.ok) {
        return null;
      }

      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      blobCache.set(url, objectUrl);
      return objectUrl;
    } catch {
      return null;
    } finally {
      inFlightRequests.delete(url);
    }
  })();

  inFlightRequests.set(url, promise);
  return promise;
}

/**
 * Invalidate and revoke cached object URLs (e.g. on photo replacement or deletion)
 * to prevent browser memory leaks.
 */
export function clearPhotoCache(url?: string): void {
  if (url) {
    const cached = blobCache.get(url);
    if (cached) {
      URL.revokeObjectURL(cached);
      blobCache.delete(url);
    }
  } else {
    for (const [, objectUrl] of blobCache.entries()) {
      URL.revokeObjectURL(objectUrl);
    }
    blobCache.clear();
  }
}

export const Avatar: React.FC<AvatarProps> = ({
  src,
  name = '',
  size = 'md',
  shape = 'circle',
  alt,
  badge = null,
  className,
  ...props
}) => {
  const [blobUrl, setBlobUrl] = useState<string | null>(() => {
    if (!src) return null;
    if (src.startsWith('data:') || src.startsWith('blob:')) return src;
    return blobCache.get(src) || null;
  });
  const [isLoading, setIsLoading] = useState(false);
  const [imageError, setImageError] = useState(false);

  useEffect(() => {
    if (!src) {
      setBlobUrl(null);
      setImageError(false);
      setIsLoading(false);
      return;
    }

    if (src.startsWith('data:') || src.startsWith('blob:')) {
      setBlobUrl(src);
      setImageError(false);
      setIsLoading(false);
      return;
    }

    const cached = blobCache.get(src);
    if (cached) {
      setBlobUrl(cached);
      setImageError(false);
      setIsLoading(false);
      return;
    }

    let isMounted = true;
    setIsLoading(true);
    setImageError(false);

    loadAuthenticatedPhotoBlob(src).then((loadedUrl) => {
      if (!isMounted) return;
      setIsLoading(false);
      if (loadedUrl) {
        setBlobUrl(loadedUrl);
        setImageError(false);
      } else {
        setImageError(true);
      }
    });

    return () => {
      isMounted = false;
    };
  }, [src]);

  // Compute initials fallback (e.g. "Rahul Sharma" -> "RS", "Aman" -> "AM")
  const getInitials = (n: string): string => {
    if (!n) return 'U';
    const parts = n.trim().split(/\s+/);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }
    return parts[0].slice(0, 2).toUpperCase();
  };

  // Generate consistent color based on student name string
  const getColorClass = (n: string): string => {
    const colors = [
      'bg-blue-600 text-white',
      'bg-indigo-600 text-white',
      'bg-emerald-600 text-white',
      'bg-violet-600 text-white',
      'bg-teal-600 text-white',
      'bg-cyan-600 text-white',
      'bg-amber-600 text-white',
      'bg-rose-600 text-white',
    ];
    let hash = 0;
    for (let i = 0; i < n.length; i++) {
      hash = n.charCodeAt(i) + ((hash << 5) - hash);
    }
    return colors[Math.abs(hash) % colors.length];
  };

  const sizes = {
    xs: 'w-6 h-6 text-[10px]',
    sm: 'w-8 h-8 text-xs',
    md: 'w-10 h-10 text-sm font-semibold',
    lg: 'w-14 h-14 text-lg font-bold',
    xl: 'w-20 h-20 text-2xl font-bold',
    '2xl': 'w-24 h-24 text-3xl font-extrabold',
  };

  const roundedClasses = shape === 'circle' ? 'rounded-full' : 'rounded-xl';

  const badgeSizes = {
    xs: 'w-1.5 h-1.5 bottom-0 right-0',
    sm: 'w-2 h-2 bottom-0 right-0',
    md: 'w-2.5 h-2.5 bottom-0 right-0',
    lg: 'w-3.5 h-3.5 bottom-0 right-0',
    xl: 'w-4 h-4 bottom-0.5 right-0.5',
    '2xl': 'w-5 h-5 bottom-1 right-1',
  };

  const badgeColors = {
    inside: 'bg-emerald-500 ring-white',
    outside: 'bg-amber-500 ring-white',
    warning: 'bg-rose-500 ring-white',
  };

  const showImage = Boolean(blobUrl && !imageError && !isLoading);
  const accessibleAlt = alt || (name ? `${name}'s profile photo` : 'Student profile photo');

  return (
    <div
      className={twMerge(
        clsx(
          'relative inline-flex items-center justify-center flex-shrink-0 select-none overflow-hidden ring-2 ring-slate-100 shadow-xs transition-all',
          sizes[size],
          roundedClasses,
          !showImage && getColorClass(name),
          isLoading && 'animate-pulse bg-slate-200',
          className
        )
      )}
      {...props}
    >
      {showImage ? (
        <img
          src={blobUrl!}
          alt={accessibleAlt}
          className={twMerge(clsx('w-full h-full object-cover object-center', roundedClasses))}
          onError={() => setImageError(true)}
          loading="lazy"
        />
      ) : (
        <span className="tracking-wider">{getInitials(name)}</span>
      )}

      {badge && (
        <span
          className={twMerge(
            clsx(
              'absolute rounded-full ring-2',
              badgeSizes[size],
              badgeColors[badge]
            )
          )}
        />
      )}
    </div>
  );
};
