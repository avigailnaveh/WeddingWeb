import { Search } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";

export function FiltersBar(props: any) {
  const { query, setQuery, filterSentiment, setFilterSentiment, sortBy, setSortBy } = props;

  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="חיפוש…"
          className="w-[220px] rounded-xl pl-9"
        />
      </div>

      <Select value={filterSentiment} onValueChange={setFilterSentiment}>
        <SelectTrigger className="w-[150px] rounded-xl">
          <SelectValue placeholder="סנטימנט" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">הכל</SelectItem>
          <SelectItem value="2">חיובי</SelectItem>
          <SelectItem value="1">נייטרלי</SelectItem>
          <SelectItem value="0">שלילי</SelectItem>
        </SelectContent>
      </Select>

      <Select value={sortBy} onValueChange={setSortBy}>
        <SelectTrigger className="w-[150px] rounded-xl">
          <SelectValue placeholder="מיון" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="newest">הכי חדש</SelectItem>
          <SelectItem value="oldest">הכי ישן</SelectItem>
        </SelectContent>
      </Select>
    </div>
  );
}
