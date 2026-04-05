import { useRef } from "react";
import { Button } from "@/components/ui/button";
import { Download, Upload, Trash2 } from "lucide-react";

export function ExportImportButtons({
  onExport,
  onImport,
  onReset,
}: {
  onExport: () => void;
  onImport: (file?: File) => void;
  onReset: () => void;
}) {
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  return (
    <div className="flex flex-wrap items-center gap-3">
      <Button 
        variant="outline" 
        onClick={onExport} 
        className="flex items-center gap-2 rounded-xl px-4 py-2"
      >
        <Download className="h-4 w-4 flex-shrink-0" /> 
        <span>ייצוא JSON</span>
      </Button>

      <input
        ref={fileInputRef}
        type="file"
        accept="application/json"
        className="hidden"
        onChange={(e) => onImport(e.target.files?.[0])}
      />
      <Button
        variant="outline"
        onClick={() => fileInputRef.current?.click()}
        className="flex items-center gap-2 rounded-xl px-4 py-2"
      >
        <Upload className="h-4 w-4 flex-shrink-0" /> 
        <span>ייבוא JSON</span>
      </Button>
    </div>
  );
}
