"use client";
import { createContext, useCallback, useContext, useState, type ReactNode } from "react";

type Toast = { id: number; text: string; kind: "ok" | "err" | "info" };
const Ctx = createContext<(text: string, kind?: Toast["kind"]) => void>(() => undefined);
let seq = 1;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Toast[]>([]);
  const push = useCallback((text: string, kind: Toast["kind"] = "info") => {
    const id = seq++;
    setItems((p) => [...p, { id, text, kind }]);
    setTimeout(() => setItems((p) => p.filter((t) => t.id !== id)), 3500);
  }, []);
  return (
    <Ctx.Provider value={push}>
      {children}
      <div className="toast-stack" aria-live="polite">
        {items.map((t) => (
          <div key={t.id} className={`toast ${t.kind === "info" ? "" : t.kind}`}>{t.text}</div>
        ))}
      </div>
    </Ctx.Provider>
  );
}

export const useToast = () => useContext(Ctx);
