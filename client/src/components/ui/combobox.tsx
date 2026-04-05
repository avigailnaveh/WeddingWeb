import * as React from "react"
import { Check, ChevronsUpDown } from "lucide-react"
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { Input } from "@/components/ui/input"

interface ComboboxProps {
  value: string
  onChange: (value: string) => void
  options: Array<{ id: number; name: string }>
  placeholder?: string
  emptyText?: string
  searchPlaceholder?: string
  disabled?: boolean
}

export function Combobox({
  value,
  onChange,
  options,
  placeholder = "בחר פריט...",
  emptyText = "לא נמצאו תוצאות",
  searchPlaceholder = "חפש...",
  disabled = false,
}: ComboboxProps) {
  const [open, setOpen] = React.useState(false)
  const [search, setSearch] = React.useState("")
  const triggerRef = React.useRef<HTMLButtonElement>(null)
  const [triggerWidth, setTriggerWidth] = React.useState<number>(0)

  React.useEffect(() => {
    if (triggerRef.current) {
      setTriggerWidth(triggerRef.current.offsetWidth)
    }
  }, [open])

  const selectedOption = options.find(
    (option) => option.id.toString() === value
  )

  // סינון אופציות לפי חיפוש
  const filteredOptions = React.useMemo(() => {
    if (!search) return options
    return options.filter((option) =>
      option.name.toLowerCase().includes(search.toLowerCase())
    )
  }, [options, search])

  const handleSelect = (optionId: string) => {
    onChange(optionId)
    setOpen(false)
    setSearch("")
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          ref={triggerRef}
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className="w-full justify-between"
          disabled={disabled}
        >
          {selectedOption ? selectedOption.name : placeholder}
          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent 
        className="p-0" 
        align="start" 
        sideOffset={4}
        style={{ width: triggerWidth > 0 ? `${triggerWidth}px` : 'auto' }}
      >
        <div className="flex flex-col w-full">
          {/* שורת חיפוש */}
          <div className="p-2 border-b">
            <Input
              placeholder={searchPlaceholder}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="h-9"
            />
          </div>
          
          {/* רשימת אפשרויות */}
          <div className="max-h-[300px] overflow-y-auto p-1">
            {filteredOptions.length === 0 ? (
              <div className="py-6 text-center text-sm text-muted-foreground">
                {emptyText}
              </div>
            ) : (
              filteredOptions.map((option) => (
                <button
                  key={option.id}
                  type="button"
                  onClick={() => handleSelect(option.id.toString())}
                  className={cn(
                    "relative flex w-full cursor-pointer select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none transition-colors",
                    "hover:bg-accent hover:text-accent-foreground",
                    "focus:bg-accent focus:text-accent-foreground",
                    value === option.id.toString() && "bg-accent"
                  )}
                >
                  <Check
                    className={cn(
                      "mr-2 h-4 w-4",
                      value === option.id.toString() ? "opacity-100" : "opacity-0"
                    )}
                  />
                  {option.name}
                </button>
              ))
            )}
          </div>
        </div>
      </PopoverContent>
    </Popover>
  )
}