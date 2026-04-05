import { useState, useEffect, useCallback } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Camera, Save, Plus, X } from "lucide-react";
import { useToast } from "@/hooks/useToast";
import { 
  ProfessionalProfile, 
  updateProfessionalProfile,
  getProfessionalOptions,
  addProfessionalItem,
  removeProfessionalItem,
  uploadProfileImage,
  ProfessionalOptions
} from "@/api/professional";
import { Combobox } from "@/components/ui/combobox";

interface DoctorProfileEditorProps {
  profile: ProfessionalProfile | null;
  onUpdate: () => void;
}

const DoctorProfileEditor = ({ profile: initialProfile, onUpdate }: DoctorProfileEditorProps) => {
  const { toast } = useToast();
  
  const [profile, setProfile] = useState({
    name: "",
    phone: "",
    email: "",
    bio: "",
  });

  const [options, setOptions] = useState<ProfessionalOptions | null>(null);
  const [newAddress, setNewAddress] = useState("");
  type ClinicAddress = { id?: number | null; address: string };

  const [addresses, setAddresses] = useState<ClinicAddress[]>([]);
  const [initialAddresses, setInitialAddresses] = useState<ClinicAddress[]>([]);

  const [profileImage, setProfileImage] = useState<string | null>(null);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [optionsVersion, setOptionsVersion] = useState(0);

  useEffect(() => {
    if (initialProfile) {
        setProfile({
        name: initialProfile.full_name,
        phone: initialProfile.phone,
        email: initialProfile.email,
        bio: initialProfile.about,
        });
        const clinicAddresses: ClinicAddress[] = initialProfile.clinic_addresses ?? [];
        setAddresses(clinicAddresses);
        setInitialAddresses(clinicAddresses);
        setProfileImage(initialProfile.profile_image);
    }
    }, [initialProfile]);

  useEffect(() => {
    // טעינת אפשרויות מהמערכת - טוען מחדש כשמשנים התמחויות ראשיות
    const loadOptions = async () => {
      const result = await getProfessionalOptions();
      if (result.ok && result.options) {
        setOptions(result.options);
      }
    };
    loadOptions();
  }, [optionsVersion]);

  const handleInputChange = (field: string, value: string) => {
    setProfile((prev) => ({ ...prev, [field]: value }));
  };

  const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      setImageFile(file);
      const reader = new FileReader();
      reader.onloadend = () => {
        setProfileImage(reader.result as string);
      };
      reader.readAsDataURL(file);
    }
  };


  const handleAddAddress = useCallback(async () => {
    const addr = newAddress.trim();
    if (!addr || !initialProfile) return;

    try {
        setAddresses(prev => [...prev, { id: null, address: addr }]);
        setNewAddress("");

        const result = await updateProfessionalProfile({
        full_name: profile.name,
        phone: profile.phone,
        email: profile.email,
        about: profile.bio,

        add_addresses: [addr],
        delete_addresses: [],
        });

        if (result.ok) {
        toast({
            title: "הכתובת נוספה בהצלחה",
            description: "הכתובת נשמרה בפרופיל",
        });

        onUpdate();
        } else {
        setAddresses(prev => prev.filter(a => a.address !== addr || a.id !== null));
        toast({
            title: "שגיאה",
            description: result.message || "לא הצלחנו להוסיף את הכתובת",
            variant: "destructive",
        });
        }
    } catch (e) {
        setAddresses(prev => prev.filter(a => a.address !== addr || a.id !== null));
        toast({
        title: "שגיאה",
        description: "אירעה שגיאה בהוספת הכתובת",
        variant: "destructive",
        });
    }
    }, [newAddress, initialProfile, profile, onUpdate, toast]);


    const handleRemoveAddress = useCallback(
    async (index: number) => {
        const addr = addresses[index];
        if (!addr) return;

        setAddresses(prev => prev.filter((_, i) => i !== index));

        try {
        if (addr.id) {
            const result = await updateProfessionalProfile({
            full_name: profile.name,
            phone: profile.phone,
            email: profile.email,
            about: profile.bio,
            add_addresses: [],
            delete_addresses: [addr.id],
            });

            if (!result.ok) {
            setAddresses(prev => {
                const copy = [...prev];
                copy.splice(index, 0, addr);
                return copy;
            });

            toast({
                title: "שגיאה",
                description: result.message || "לא הצלחנו למחוק את הכתובת",
                variant: "destructive",
            });
            return;
            }

            toast({
            title: "הכתובת נמחקה",
            description: "הכתובת הוסרה מהפרופיל",
            });

            onUpdate();
        }
        } catch (e) {
        setAddresses(prev => {
            const copy = [...prev];
            copy.splice(index, 0, addr);
            return copy;
        });

        toast({
            title: "שגיאה",
            description: "אירעה שגיאה במחיקת הכתובת",
            variant: "destructive",
        });
        }
    },
    [addresses, profile, onUpdate, toast]
    );


  const handleAddItem = async (type: string, itemId: number) => {
    try {
      const result = await addProfessionalItem(type, itemId);
      if (result.ok) {
        toast({
          title: "הפריט נוסף בהצלחה",
          description: "השינוי עודכן בפרופיל שלך",
        });
        
        // אם הוספנו התמחות ראשית, נטען מחדש את האפשרויות לסינון תת-התמחויות
        if (type === "main_specialization" || type === "main_care") {
          setOptionsVersion(v => v + 1);
        }
        
        onUpdate();
      } else {
        toast({
          title: "שגיאה",
          description: result.error || "לא הצלחנו להוסיף את הפריט",
          variant: "destructive",
        });
      }
    } catch (error) {
      toast({
        title: "שגיאה",
        description: "אירעה שגיאה בהוספת הפריט",
        variant: "destructive",
      });
    }
  };

  const handleRemoveItem = async (type: string, itemId: number) => {
    try {
      const result = await removeProfessionalItem(type, itemId);
      if (result.ok) {
        toast({
          title: "הפריט הוסר בהצלחה",
          description: "השינוי עודכן בפרופיל שלך",
        });
        
        // אם הסרנו התמחות ראשית, נטען מחדש את האפשרויות לסינון תת-התמחויות
        if (type === "main_specialization" || type === "main_care") {
          setOptionsVersion(v => v + 1);
        }
        
        onUpdate();
      } else {
        toast({
          title: "שגיאה",
          description: result.error || "לא הצלחנו להסיר את הפריט",
          variant: "destructive",
        });
      }
    } catch (error) {
      toast({
        title: "שגיאה",
        description: "אירעה שגיאה בהסרת הפריט",
        variant: "destructive",
      });
    }
  };

  const handleSave = async () => {
    try {
        const newAddresses = addresses
        .filter(a => !a.id)
        .map(a => a.address);

        const currentIds = new Set(
        addresses
            .filter(a => a.id != null)
            .map(a => a.id as number)
        );

        const deletedAddresses = initialAddresses
        .filter(a => a.id != null && !currentIds.has(a.id as number))
        .map(a => a.id as number);


        const hasChanges = 
            imageFile !== null || // תמונה חדשה
            profile.name !== initialProfile?.full_name ||
            profile.phone !== initialProfile?.phone ||
            profile.email !== initialProfile?.email ||
            profile.bio !== initialProfile?.about ||
            newAddresses.length > 0 || deletedAddresses.length > 0; 

      if (!hasChanges) {
        toast({
          title: "אין שינויים לשמירה",
          description: "לא בוצעו שינויים בפרופיל",
        });
        return;
      }

      // אם יש תמונה חדשה להעלאה
      if (imageFile) {
        const uploadResult = await uploadProfileImage(imageFile);
        if (!uploadResult.ok) {
          toast({
            title: "שגיאה בהעלאת תמונה",
            description: uploadResult.error || "לא הצלחנו להעלות את התמונה",
            variant: "destructive",
          });
          return;
        }
      }

      // עדכון הפרופיל - שולח את כל הכתובות הנוכחיות
      const result = await updateProfessionalProfile({
        full_name: profile.name,
        phone: profile.phone,
        email: profile.email,
        about: profile.bio,
        add_addresses: newAddresses,
        delete_addresses: deletedAddresses,
      });

      if (result.ok) {
        toast({
          title: "הפרופיל נשמר בהצלחה",
          description: "השינויים שלך עודכנו",
        });
        // עדכון הכתובות המקוריות אחרי שמירה מוצלחת
        setInitialAddresses(addresses);
        setImageFile(null); // איפוס קובץ התמונה
        onUpdate();
      } else {
        toast({
          title: "שגיאה",
          description: "לא הצלחנו לשמור את השינויים",
          variant: "destructive",
        });
      }
    } catch (error) {
      toast({
        title: "שגיאה",
        description: "אירעה שגיאה בשמירת הפרופיל",
        variant: "destructive",
      });
    }
  };

  const EditableTagListSection = ({
    title,
    items,
    type,
    availableOptions,
    variant = "primary",
  }: {
    title: string;
    items: Array<{ id: number; name: string }>;
    type: string;
    availableOptions?: Array<{ id: number; name: string }>;
    variant?: "primary" | "outline";
  }) => {
    const [selectedId, setSelectedId] = useState<string>("");

    const handleAdd = () => {
      if (selectedId) {
        handleAddItem(type, parseInt(selectedId));
        setSelectedId("");
      }
    };

    return (
      <Card className="shadow-card border-0">
        <CardHeader>
          <CardTitle className="text-lg">{title}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* רשימת פריטים קיימים */}
          <div className="flex flex-wrap gap-2">
            {items.length === 0 ? (
              <p className="text-sm text-muted-foreground">אין פריטים להצגה</p>
            ) : (
              items.map((item) => (
                <Badge
                  key={item.id}
                  variant={variant === "primary" ? "secondary" : "outline"}
                  className={`px-3 py-1.5 flex items-center gap-2 ${
                    variant === "primary" ? "bg-primary/10 text-primary" : ""
                  }`}
                >
                  {item.name}
                  <button
                    onClick={() => handleRemoveItem(type, item.id)}
                    className="hover:text-destructive transition-colors"
                  >
                    <X className="w-3 h-3" />
                  </button>
                </Badge>
              ))
            )}
          </div>

          {/* הוספת פריט חדש */}
          {availableOptions && availableOptions.length > 0 && (
            <div className="flex gap-2 items-center">
              <div className="flex-1">
                <Combobox
                  value={selectedId}
                  onChange={setSelectedId}
                  options={availableOptions.filter((opt) => !items.some((item) => item.id === opt.id))}
                  placeholder={`בחר ${title} להוספה`}
                  searchPlaceholder="חפש..."
                  emptyText="לא נמצאו תוצאות"
                />
              </div>
              <Button 
                onClick={handleAdd} 
                disabled={!selectedId}
                size="sm"
                className="gap-2 shrink-0"
              >
                <Plus className="w-4 h-4" />
                הוסף
              </Button>
            </div>
          )}
        </CardContent>
      </Card>
    );
  };

  const getInitials = (name: string) => {
    if (!name) return "?";
    const parts = name.split(" ");
    if (parts.length >= 2) {
      return parts[0][0] + parts[1][0];
    }
    return name[0];
  };

  if (!initialProfile) {
    return (
      <div className="flex items-center justify-center py-12">
        <p className="text-muted-foreground">טוען פרופיל...</p>
      </div>
    );
  }

  return (
    <div dir="rtl" className="space-y-6 text-right pr-0 pl-0">
      {/* Profile Picture */}
      <Card className="shadow-card border-0 ">
        <CardHeader>
          <CardTitle className="text-lg">תמונת פרופיל</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex items-center gap-6">
            <div className="relative">
              <Avatar className="w-24 h-24 ring-4 ring-primary/10">
                <AvatarImage 
                  src={profileImage || undefined} 
                  alt={profile.name} 
                />
                <AvatarFallback className="text-2xl">
                  {getInitials(profile.name)}
                </AvatarFallback>
              </Avatar>
              <label 
                htmlFor="profile-image" 
                className="absolute bottom-0 left-0 w-8 h-8 bg-primary rounded-full flex items-center justify-center text-primary-foreground shadow-lg hover:bg-primary/90 transition-colors cursor-pointer"
              >
                <Camera className="w-4 h-4" />
              </label>
              <input
                id="profile-image"
                type="file"
                accept="image/*"
                className="hidden"
                onChange={handleImageChange}
              />
            </div>
            <div className="text-sm text-muted-foreground">
              <p>גודל מומלץ: 400x400 פיקסלים</p>
              <p>פורמטים: JPG, PNG</p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Basic Info */}
      <Card className="shadow-card border-0">
        <CardHeader>
          <CardTitle className="text-lg">פרטים אישיים</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid md:grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="name">שם מלא</Label>
              <Input
                id="name"
                value={profile.name}
                onChange={(e) => handleInputChange("name", e.target.value)}
                placeholder="ד״ר ישראל ישראלי"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="phone">טלפון</Label>
              <Input
                id="phone"
                value={profile.phone}
                onChange={(e) => handleInputChange("phone", e.target.value)}
                placeholder="03-1234567"
                dir="ltr"
                className="text-left"
              />
            </div>
            <div className="space-y-2 md:col-span-2">
              <Label htmlFor="email">אימייל</Label>
              <Input
                id="email"
                type="email"
                value={profile.email}
                onChange={(e) => handleInputChange("email", e.target.value)}
                placeholder="doctor@example.com"
                dir="ltr"
                className="text-left"
              />
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="bio">אודות</Label>
            <Textarea
              id="bio"
              value={profile.bio}
              onChange={(e) => handleInputChange("bio", e.target.value)}
              placeholder="ספר על עצמך, הניסיון שלך והגישה הרפואית..."
              rows={4}
            />
          </div>
        </CardContent>
      </Card>

      {/* התמחויות ראשיות */}
      <EditableTagListSection
        title="התמחויות ראשיות"
        items={initialProfile.primary_specialties}
        type="main_specialization"
        availableOptions={options?.main_specializations}
        variant="primary"
      />

      {/* התמחויות משניות */}
      <EditableTagListSection
        title="התמחויות משניות"
        items={initialProfile.secondary_specialties}
        type="expertise"
        availableOptions={options?.expertises}
        variant="primary"
      />

      {/* שפות */}
      <EditableTagListSection
        title="שפות"
        items={initialProfile.languages}
        type="language"
        availableOptions={options?.languages}
        variant="primary"
      />

      {/* קופות חולים */}
      <EditableTagListSection
        title="קופות חולים"
        items={initialProfile.health_funds}
        type="company"
        availableOptions={options?.companies}
        variant="primary"
      />

      {/* ביטוחים */}
      <EditableTagListSection
        title="ביטוחים"
        items={initialProfile.insurances}
        type="insurance"
        availableOptions={options?.insurances}
        variant="primary"
      />

      {/* כתובות מרפאות */}
      <Card className="shadow-card border-0">
        <CardHeader>
          <CardTitle className="text-lg">כתובות מרפאות</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* רשימת כתובות */}
          <div className="flex flex-wrap gap-2">
            {addresses.length === 0 ? (
              <p className="text-sm text-muted-foreground">אין כתובות להצגה</p>
            ) : (
              addresses.map((address, index) => (
                <Badge key={index} variant="secondary" className="px-3 py-1.5 flex items-center gap-2 bg-primary/10 text-primary">
                  {address.address}
                  <button
                    onClick={() => handleRemoveAddress(index)}
                    className="hover:text-destructive transition-colors"
                  >
                    <X className="w-3 h-3" />
                  </button>
                </Badge>
              ))
            )}
          </div>

          {/* הוספת כתובת חדשה */}
          <div className="flex gap-2 items-center">
            <div className="flex-1">
              <Input
                value={newAddress}
                onChange={(e) => setNewAddress(e.target.value)}
                placeholder="הכנס כתובת מרפאה חדשה"
                onKeyPress={(e) => {
                  if (e.key === "Enter" && newAddress.trim()) {
                    handleAddAddress();
                  }
                }}
              />
            </div>
            <Button 
              onClick={handleAddAddress} 
              disabled={!newAddress.trim()}
              size="sm"
              className="gap-2 shrink-0"
            >
              <Plus className="w-4 h-4" />
              הוסף
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Save Button */}
      <div className="flex justify-end">
        <Button onClick={handleSave} size="lg" className="gradient-primary gap-2">
          <Save className="w-4 h-4" />
          שמור שינויים
        </Button>
      </div>
    </div>
  );
};

export default DoctorProfileEditor;