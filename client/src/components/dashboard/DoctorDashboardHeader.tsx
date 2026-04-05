import { Bell, LogOut } from "lucide-react";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { ProfessionalProfile } from "@/api/professional";

interface DoctorDashboardHeaderProps {
  profile: ProfessionalProfile | null;
}

const DoctorDashboardHeader = ({ profile }: DoctorDashboardHeaderProps) => {
  const getInitials = (name: string) => {
    if (!name) return "?";
    const parts = name.split(" ");
    if (parts.length >= 2) {
      return parts[0][0] + parts[1][0];
    }
    return name[0];
  };

  const getPrimarySpecialty = () => {
    if (!profile || !profile.primary_specialties || profile.primary_specialties.length === 0) {
      return "רופא";
    }
    // אם זה אובייקט עם name, הצג את השם
    const firstSpecialty = profile.primary_specialties[0];
    if (typeof firstSpecialty === 'object' && firstSpecialty.name) {
      return firstSpecialty.name;
    }
    // אם זה string, הצג אותו
    return String(firstSpecialty);
  };

  return (
    <header className="sticky top-0 z-50 w-full border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
      <div className="container flex h-16 items-center justify-between px-4">
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2">
            <div className="text-2xl font-bold bg-gradient-to-r from-primary to-accent bg-clip-text text-transparent">
              DocFind
            </div>
            <Badge variant="secondary" className="text-xs">
              פאנל רופא
            </Badge>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <div className="hidden md:flex items-center gap-3 border-l pl-4">
            <div className="text-right">
              <p className="text-sm font-medium">{profile?.full_name || "טוען..."}</p>
              <p className="text-xs text-muted-foreground">{getPrimarySpecialty()}</p>
            </div>
            <Avatar className="h-9 w-9">
              <AvatarImage
                src={profile?.profile_image || undefined}
                alt={profile?.full_name}
              />
              <AvatarFallback>
                {profile ? getInitials(profile.full_name) : "?"}
              </AvatarFallback>
            </Avatar>
          </div>

          <Button variant="ghost" size="icon" className="relative">
            <Bell className="h-5 w-5" />
            <span className="absolute top-1 right-1 h-2 w-2 rounded-full bg-destructive" />
          </Button>

          <Button variant="ghost" size="icon">
            <LogOut className="h-5 w-5" />
          </Button>
        </div>
      </div>
    </header>
  );
};

export default DoctorDashboardHeader;