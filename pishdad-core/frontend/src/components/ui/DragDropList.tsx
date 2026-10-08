"use client";
import { useMemo } from "react";
import {
  DndContext,
  PointerSensor,
  TouchSensor,
  KeyboardSensor,
  useSensor,
  useSensors,
  closestCenter,
  type DragEndEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  useSortable,
  verticalListSortingStrategy,
  sortableKeyboardCoordinates,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";

/**
 * لیست sortable موبایل‌فرندلی با dnd-kit (تاچ + کیبورد + ماوس).
 * API بدون تغییر: items/onMove/render + دکمه‌های بالا/پایین برای دسترس‌پذیری.
 * دلیل مهاجرت از native HTML5: تاچ موبایل (بک‌اند/ادمین موبایل‌فرندلی).
 *
 * TODO(کم‌ارزش — عمداً مهاجرت نشد):
 * - سطرهای LinkListEditor: مرتب‌سازی درون‌هم‌سطحی با دکمه‌های ↑/↓ + indent/outdent
 *   کافی است (درخت تودرتو با dnd چندسطحی پیچیدگی/ریسک بالا، ارزش کم).
 * - آیتم‌های FAQ داخل SchemaForm و media_ids گالری: ویرایش آرایه‌ای ساده با دکمه
 *   کافی است؛ dnd در آینده اگر SchemaForm آرایهsortable شد.
 */
export function DragDropList<T extends { id: string | number }>({
  items, onMove, render,
}: {
  items: T[];
  onMove: (from: number, to: number) => void;
  render: (item: T, index: number, helpers: { moveUp: () => void; moveDown: () => void }) => React.ReactNode;
}) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 150, tolerance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );
  const ids = useMemo(() => items.map((it) => String(it.id)), [items]);

  const move = (from: number, to: number) => {
    if (to < 0 || to >= items.length || from === to) return;
    onMove(from, to);
  };

  const onDragEnd = (e: DragEndEvent) => {
    const { active, over } = e;
    if (!over || active.id === over.id) return;
    const from = ids.indexOf(String(active.id));
    const to = ids.indexOf(String(over.id));
    if (from >= 0 && to >= 0) move(from, to);
  };

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
      <SortableContext items={ids} strategy={verticalListSortingStrategy}>
        <div role="list" aria-label="لیست قابل مرتب‌سازی">
          {items.map((item, i) => (
            <SortableRow key={String(item.id)} id={String(item.id)}>
              {render(item, i, { moveUp: () => move(i, i - 1), moveDown: () => move(i, i + 1) })}
            </SortableRow>
          ))}
        </div>
      </SortableContext>
    </DndContext>
  );
}

function SortableRow({ id, children }: { id: string; children: React.ReactNode }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id });
  return (
    <div role="listitem">
      <div
        ref={setNodeRef}
        style={{
          transform: CSS.Transform.toString(transform),
          transition,
          opacity: isDragging ? 0.45 : undefined,
          touchAction: "manipulation",
        }}
        {...attributes}
        {...listeners}
      >
        {children}
      </div>
    </div>
  );
}
