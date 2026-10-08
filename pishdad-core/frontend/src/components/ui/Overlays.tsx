"use client";

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="modal-scrim" onClick={onClose} role="presentation">
      <div className="modal" role="dialog" aria-modal="true" aria-label={title} onClick={(e) => e.stopPropagation()}>
        <div className="overlay-head">
          <b>{title}</b>
          <div className="overlay-head-spacer" />
          <button className="icon-btn" onClick={onClose} aria-label="بستن">✕</button>
        </div>
        <div className="modal-content">{children}</div>
      </div>
    </div>
  );
}

export function Drawer({ title, onClose, children, wide }: { title: string; onClose: () => void; children: React.ReactNode; wide?: boolean }) {
  return (
    <>
      <div className="drawer-scrim" onClick={onClose} />
      <aside className={`drawer${wide ? " wide" : ""}`} role="dialog" aria-modal="true" aria-label={title}>
        <div className="overlay-head">
          <b>{title}</b>
          <div className="overlay-head-spacer" />
          <button className="icon-btn" onClick={onClose} aria-label="بستن">✕</button>
        </div>
        {children}
      </aside>
    </>
  );
}

export function ConfirmDialog({
  title, text, confirmLabel = "تأیید", onConfirm, onCancel,
}: {
  title: string; text: string; confirmLabel?: string; onConfirm: () => void; onCancel: () => void;
}) {
  return (
    <Modal title={title} onClose={onCancel}>
      <p style={{ fontSize: 13.5, color: "var(--text-muted)" }}>{text}</p>
      <div style={{ display: "flex", gap: 8, marginBlockStart: 16 }}>
        <button className="btn btn-ghost" onClick={onCancel}>انصراف</button>
        <button className="btn btn-primary" onClick={onConfirm}>{confirmLabel}</button>
      </div>
    </Modal>
  );
}
