import type { PendingAssetSelection } from "@/lib/effectUploadTypes";

const DB_NAME = "upload_previews";
const STORE_NAME = "previews";
const PENDING_STORE_NAME = "pending_uploads";
const PENDING_ASSET_STORE_NAME = "pending_asset_uploads";
const DB_VERSION = 3;
const MAX_AGE_MS = 1000 * 60 * 60 * 6;

type PreviewRecord = {
  videoId: number;
  file: File;
  createdAt: number;
};

type PendingUploadRecord = {
  uploadId: string;
  file: File;
  createdAt: number;
};

type PendingAssetRecord = {
  key: string;
  uploadId: string;
  propertyKey: string;
  kind: "image" | "video";
  file: File;
  createdAt: number;
};

function makePendingAssetKey(uploadId: string, propertyKey: string): string {
  return `${uploadId}:${propertyKey}`;
}

function canUseIndexedDb() {
  return typeof window !== "undefined" && "indexedDB" in window;
}

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = window.indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: "videoId" });
      }
      if (!db.objectStoreNames.contains(PENDING_STORE_NAME)) {
        db.createObjectStore(PENDING_STORE_NAME, { keyPath: "uploadId" });
      }
      if (!db.objectStoreNames.contains(PENDING_ASSET_STORE_NAME)) {
        const store = db.createObjectStore(PENDING_ASSET_STORE_NAME, { keyPath: "key" });
        store.createIndex("byUploadId", "uploadId", { unique: false });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function transactionDone(tx: IDBTransaction): Promise<void> {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error);
  });
}

export async function savePreview(videoId: number, file: File): Promise<void> {
  if (!canUseIndexedDb()) return;

  const db = await openDb();
  try {
    const tx = db.transaction(STORE_NAME, "readwrite");
    const store = tx.objectStore(STORE_NAME);
    store.put({ videoId, file, createdAt: Date.now() } satisfies PreviewRecord);
    await transactionDone(tx);

    await purgeExpired(db);
  } catch {
    // ignore storage failures
  } finally {
    db.close();
  }
}

export async function loadPreview(videoId: number): Promise<File | null> {
  if (!canUseIndexedDb()) return null;

  const db = await openDb();
  try {
    const tx = db.transaction(STORE_NAME, "readonly");
    const store = tx.objectStore(STORE_NAME);
    const request = store.get(videoId);
    const record = await new Promise<PreviewRecord | undefined>((resolve, reject) => {
      request.onsuccess = () => resolve(request.result as PreviewRecord | undefined);
      request.onerror = () => reject(request.error);
    });
    await transactionDone(tx);

    if (!record) return null;
    if (Date.now() - record.createdAt > MAX_AGE_MS) {
      await deletePreview(videoId);
      return null;
    }

    return record.file;
  } catch {
    return null;
  } finally {
    db.close();
  }
}

export async function deletePreview(videoId: number): Promise<void> {
  if (!canUseIndexedDb()) return;

  const db = await openDb();
  try {
    const tx = db.transaction(STORE_NAME, "readwrite");
    const store = tx.objectStore(STORE_NAME);
    store.delete(videoId);
    await transactionDone(tx);
  } catch {
    // ignore storage failures
  } finally {
    db.close();
  }
}

export async function savePendingUpload(uploadId: string, file: File): Promise<void> {
  if (!canUseIndexedDb()) return;

  const db = await openDb();
  try {
    const tx = db.transaction(PENDING_STORE_NAME, "readwrite");
    const store = tx.objectStore(PENDING_STORE_NAME);
    store.put({ uploadId, file, createdAt: Date.now() } satisfies PendingUploadRecord);
    await transactionDone(tx);

    await purgeExpired(db, PENDING_STORE_NAME);
  } catch {
    // ignore storage failures
  } finally {
    db.close();
  }
}

export async function loadPendingUpload(uploadId: string): Promise<File | null> {
  if (!canUseIndexedDb()) return null;

  const db = await openDb();
  try {
    const tx = db.transaction(PENDING_STORE_NAME, "readonly");
    const store = tx.objectStore(PENDING_STORE_NAME);
    const request = store.get(uploadId);
    const record = await new Promise<PendingUploadRecord | undefined>((resolve, reject) => {
      request.onsuccess = () => resolve(request.result as PendingUploadRecord | undefined);
      request.onerror = () => reject(request.error);
    });
    await transactionDone(tx);

    if (!record) return null;
    if (Date.now() - record.createdAt > MAX_AGE_MS) {
      await deletePendingUpload(uploadId);
      return null;
    }

    return record.file;
  } catch {
    return null;
  } finally {
    db.close();
  }
}

export async function deletePendingUpload(uploadId: string): Promise<void> {
  if (!canUseIndexedDb()) return;

  const db = await openDb();
  try {
    const tx = db.transaction(PENDING_STORE_NAME, "readwrite");
    const store = tx.objectStore(PENDING_STORE_NAME);
    store.delete(uploadId);
    await transactionDone(tx);
  } catch {
    // ignore storage failures
  } finally {
    db.close();
  }
}

export async function savePendingAssets(
  uploadId: string,
  assets: PendingAssetSelection[],
): Promise<void> {
  if (!canUseIndexedDb()) return;
  if (assets.length === 0) return;

  await deletePendingAssets(uploadId);

  const db = await openDb();
  try {
    const tx = db.transaction(PENDING_ASSET_STORE_NAME, "readwrite");
    const store = tx.objectStore(PENDING_ASSET_STORE_NAME);
    const createdAt = Date.now();
    assets.forEach((asset) => {
      const key = makePendingAssetKey(uploadId, asset.propertyKey);
      store.put({
        key,
        uploadId,
        propertyKey: asset.propertyKey,
        kind: asset.kind,
        file: asset.file,
        createdAt,
      } satisfies PendingAssetRecord);
    });
    await transactionDone(tx);

    await purgeExpired(db, PENDING_ASSET_STORE_NAME);
  } catch {
    // ignore storage failures
  } finally {
    db.close();
  }
}

export async function loadPendingAssets(uploadId: string): Promise<PendingAssetSelection[]> {
  if (!canUseIndexedDb()) return [];

  const db = await openDb();
  try {
    const tx = db.transaction(PENDING_ASSET_STORE_NAME, "readonly");
    const store = tx.objectStore(PENDING_ASSET_STORE_NAME);
    const index = store.index("byUploadId");
    const request = index.getAll(uploadId);
    const records = await new Promise<PendingAssetRecord[]>((resolve, reject) => {
      request.onsuccess = () => resolve((request.result as PendingAssetRecord[]) ?? []);
      request.onerror = () => reject(request.error);
    });
    await transactionDone(tx);

    const expired = records.some((record) => Date.now() - record.createdAt > MAX_AGE_MS);
    if (expired) {
      await deletePendingAssets(uploadId);
      return [];
    }

    return records.map((record) => ({
      propertyKey: record.propertyKey,
      kind: record.kind,
      file: record.file,
    }));
  } catch {
    return [];
  } finally {
    db.close();
  }
}

export async function deletePendingAssets(uploadId: string): Promise<void> {
  if (!canUseIndexedDb()) return;

  const db = await openDb();
  try {
    const tx = db.transaction(PENDING_ASSET_STORE_NAME, "readwrite");
    const store = tx.objectStore(PENDING_ASSET_STORE_NAME);
    const index = store.index("byUploadId");
    await new Promise<void>((resolve, reject) => {
      const request = index.openCursor(uploadId);
      request.onsuccess = () => {
        const cursor = request.result;
        if (cursor) {
          cursor.delete();
          cursor.continue();
          return;
        }
        resolve();
      };
      request.onerror = () => reject(request.error);
    });
    await transactionDone(tx);
  } catch {
    // ignore storage failures
  } finally {
    db.close();
  }
}

async function purgeExpired(db: IDBDatabase, storeName: string = STORE_NAME): Promise<void> {
  const threshold = Date.now() - MAX_AGE_MS;

  const tx = db.transaction(storeName, "readwrite");
  const store = tx.objectStore(storeName);

  await new Promise<void>((resolve, reject) => {
    const request = store.openCursor();

    request.onsuccess = () => {
      const cursor = request.result;
      if (!cursor) return;

      const value = cursor.value as PreviewRecord | PendingUploadRecord;
      if (value.createdAt < threshold) {
        cursor.delete();
      }
      cursor.continue();
    };

    request.onerror = () => reject(request.error);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error);
  });
}

