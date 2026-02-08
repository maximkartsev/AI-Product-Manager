"use client";

import { useEffect, useState } from "react";
import { getAccessToken } from "@/lib/api";

export default function useAuthToken(): string | null {
  const [token, setToken] = useState<string | null>(null);

  useEffect(() => {
    const updateToken = () => setToken(getAccessToken());
    updateToken();

    window.addEventListener("auth:changed", updateToken);
    const handleStorage = (event: StorageEvent) => {
      if (event.key === "auth_token") updateToken();
    };
    window.addEventListener("storage", handleStorage);

    return () => {
      window.removeEventListener("auth:changed", updateToken);
      window.removeEventListener("storage", handleStorage);
    };
  }, []);

  return token;
}
