export type GroupedResult<T> = {
  key: string;
  title: string;
  items: T[];
};

export function groupByOrdered<T>(
  items: T[],
  getKey: (item: T) => string | null | undefined,
  getTitle: (item: T) => string,
): GroupedResult<T>[] {
  const order: string[] = [];
  const map = new Map<string, GroupedResult<T>>();

  items.forEach((item, index) => {
    const rawKey = getKey(item);
    const fallbackKey = `idx:${index}`;
    const key = rawKey && rawKey.trim() !== "" ? rawKey : fallbackKey;

    if (!map.has(key)) {
      map.set(key, { key, title: getTitle(item), items: [] });
      order.push(key);
    }
    map.get(key)!.items.push(item);
  });

  return order.map((key) => map.get(key)!);
}

