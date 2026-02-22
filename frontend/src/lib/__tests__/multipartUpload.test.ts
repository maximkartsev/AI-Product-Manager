import { describe, expect, it, vi } from "vitest";
import { uploadMultipartParts } from "../multipartUpload";

type PartUrl = { part_number: number; url: string };

const makeResponse = (etag?: string, ok = true) =>
  ({
    ok,
    status: ok ? 200 : 500,
    headers: {
      get: (name: string) => {
        if (etag && name.toLowerCase() === "etag") return etag;
        return null;
      },
    },
  }) as Response;

describe("uploadMultipartParts", () => {
  it("respects the concurrency limit", async () => {
    const file = new File([new Uint8Array(10)], "model.bin", { type: "application/octet-stream" });
    const partUrls: PartUrl[] = Array.from({ length: 5 }, (_, index) => ({
      part_number: index + 1,
      url: `https://example.com/part-${index + 1}`,
    }));

    let active = 0;
    let maxActive = 0;
    const fetchMock = vi.fn().mockImplementation(() => {
      active += 1;
      maxActive = Math.max(maxActive, active);
      return new Promise<Response>((resolve) => {
        setTimeout(() => {
          active -= 1;
          resolve(makeResponse('"etag"'));
        }, 10);
      });
    });

    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);

    const parts = await uploadMultipartParts({
      file,
      partSize: 1,
      partUrls,
      contentType: "application/octet-stream",
      concurrency: 2,
    });

    expect(maxActive).toBe(2);
    expect(parts).toHaveLength(5);
    vi.unstubAllGlobals();
  });

  it("throws on missing ETag and stops further uploads", async () => {
    const file = new File([new Uint8Array(10)], "model.bin", { type: "application/octet-stream" });
    const partUrls: PartUrl[] = [
      { part_number: 1, url: "https://example.com/part-1" },
      { part_number: 2, url: "https://example.com/part-2" },
      { part_number: 3, url: "https://example.com/part-3" },
    ];

    const signals: AbortSignal[] = [];
    const fetchMock = vi.fn().mockImplementation((_url: string, init?: RequestInit) => {
      const signal = init?.signal as AbortSignal | undefined;
      if (signal) signals.push(signal);

      if (signals.length === 1) {
        return Promise.resolve(makeResponse(undefined));
      }

      return new Promise<Response>((_resolve, reject) => {
        if (!signal) {
          reject(new Error("Missing abort signal."));
          return;
        }
        signal.addEventListener("abort", () => {
          reject(new DOMException("Aborted", "AbortError"));
        });
      });
    });

    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);

    await expect(
      uploadMultipartParts({
        file,
        partSize: 1,
        partUrls,
        contentType: "application/octet-stream",
        concurrency: 2,
      }),
    ).rejects.toThrow("Missing ETag");

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(signals.some((signal) => signal.aborted)).toBe(true);
    vi.unstubAllGlobals();
  });

  it("returns ordered parts with cleaned ETags", async () => {
    const file = new File([new Uint8Array(10)], "model.bin", { type: "application/octet-stream" });
    const partUrls: PartUrl[] = [
      { part_number: 2, url: "https://example.com/part-2" },
      { part_number: 1, url: "https://example.com/part-1" },
    ];

    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(makeResponse('"etag-2"'))
      .mockResolvedValueOnce(makeResponse('"etag-1"'));

    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);

    const parts = await uploadMultipartParts({
      file,
      partSize: 1,
      partUrls,
      contentType: "application/octet-stream",
      concurrency: 2,
    });

    expect(parts).toEqual([
      { part_number: 1, etag: "etag-1" },
      { part_number: 2, etag: "etag-2" },
    ]);
    vi.unstubAllGlobals();
  });
});
