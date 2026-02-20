export type PendingAssetSelection = {
  propertyKey: string;
  kind: "image" | "video";
  file: File;
};

export type PendingAssetsMap = Record<string, PendingAssetSelection>;
