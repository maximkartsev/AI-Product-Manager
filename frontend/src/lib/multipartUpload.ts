export type MultipartPartUrl = { part_number: number; url: string };
export type MultipartUploadPart = { part_number: number; etag: string };

type UploadMultipartPartsOptions = {
  file: File;
  partSize: number;
  partUrls: MultipartPartUrl[];
  contentType: string;
  concurrency?: number;
};

export async function uploadMultipartParts({
  file,
  partSize,
  partUrls,
  contentType,
  concurrency = 4,
}: UploadMultipartPartsOptions): Promise<MultipartUploadPart[]> {
  if (partUrls.length === 0) return [];

  const controllers = new Map<number, AbortController>();
  const results: MultipartUploadPart[] = [];
  let cursor = 0;
  let activeError: Error | null = null;

  const maxConcurrency = Math.max(1, Math.floor(concurrency));
  const workerCount = Math.min(maxConcurrency, partUrls.length);

  const runWorker = async () => {
    while (true) {
      const index = cursor++;
      if (index >= partUrls.length) return;
      if (activeError) return;

      const part = partUrls[index];
      const start = (part.part_number - 1) * partSize;
      const end = Math.min(start + partSize, file.size);
      const chunk = file.slice(start, end);

      const controller = new AbortController();
      controllers.set(part.part_number, controller);

      try {
        const response = await fetch(part.url, {
          method: "PUT",
          headers: { "Content-Type": contentType },
          body: chunk,
          signal: controller.signal,
        });
        if (!response.ok) {
          throw new Error(`Part ${part.part_number} upload failed (${response.status}).`);
        }

        const etag = response.headers.get("ETag");
        if (!etag) {
          throw new Error(`Missing ETag for part ${part.part_number}.`);
        }

        results.push({ part_number: part.part_number, etag: etag.replace(/"/g, "") });
      } catch (error) {
        if (!activeError) {
          activeError = error instanceof Error ? error : new Error("Multipart upload failed.");
          controllers.forEach((ctrl) => ctrl.abort());
        }
        return;
      } finally {
        controllers.delete(part.part_number);
      }
    }
  };

  await Promise.all(Array.from({ length: workerCount }, runWorker));

  if (activeError) {
    throw activeError;
  }

  return results.sort((a, b) => a.part_number - b.part_number);
}
