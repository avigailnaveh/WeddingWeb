import { useState, useEffect, useRef } from "react";
import heroBgCurve from "@/assets/hero-bg-curve.jpeg";
import { MEMBER_URL, GPT_CHAT_API_URL } from "@/config/config";
import { Send, Bot, User, X, ChevronLeft } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";
import DoctorCard from "@/components/DoctorCard";
import DoctorFilters, { type SortOption, type GenderFilter } from "@/components/DoctorFilters";
import { fetchMemberRecommendations } from "@/api/recommendations";
import type { DoctorData, ProfessionalProfile, UserRecommendation } from "@/types/doctor.types";
import { profileToDoctor } from "@/types/doctor.types";
import logoIcon from "@/assets/logo.svg";
import TypingIndicator from "@/components/TypingIndicator";

interface SpecialtyOption {
  type: string;
  id: number;
  name: string;
  description?: string;
}

interface Message {
  role: "user" | "assistant";
  content: string;
  doctors?: DoctorData[];
  options?: SpecialtyOption[];
}

const SPECIALTIES = [
  "רופא נשים",
  "רופא שיניים לילדים",
  "אף אוזן גרון",
  "פסיכולוגיה",
  "אורתופדיה",
  "פיזיותרפיה",
  "קלינאית תקשורת",
  "עיניים",
  "רופא ילדים",
  "רופא משפחה",
];

export default function ChatPage() {
  const [messages, setMessages] = useState<Message[]>([]);
  const [input, setInput] = useState("");
  const [categoryTitle, setCategoryTitle] = useState("");
  const [isTyping, setIsTyping] = useState(false);
  const [userRecommendations, setUserRecommendations] = useState<Record<number, UserRecommendation>>({});
  const [sortBy, setSortBy] = useState<SortOption>("recommendations");
  const [genderFilter, setGenderFilter] = useState<GenderFilter>("all");
  const [userLocation, setUserLocation] = useState<{ lat: number; lng: number } | null>(null);
  const messagesEndRef = useRef<HTMLDivElement | null>(null);
  const specialtiesRef = useRef<HTMLDivElement | null>(null);

  const hasMessages = messages.length > 0;

  useEffect(() => {
    loadUserRecommendations();
    loadMemberLocation();
  }, []);

  const loadMemberLocation = async () => {
    try {
      const response = await fetch(MEMBER_URL, {
        method: "GET",
        credentials: "include",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
      });
      const data = await response.json();
      if (data?.ok && data?.member?.lat != null && data?.member?.lng != null) {
        setUserLocation({ lat: Number(data.member.lat), lng: Number(data.member.lng) });
      } else {
        setUserLocation(null);
      }
    } catch {
      setUserLocation(null);
    }
  };

  const loadUserRecommendations = async () => {
    try {
      const recommendations = await fetchMemberRecommendations(1);
      setUserRecommendations(recommendations);
    } catch {
      // silent
    }
  };

  const handleRecommendationUpdate = (professionalId: number, recommendationData: UserRecommendation | null) => {
    setUserRecommendations((prev) => {
      if (recommendationData === null) {
        const newRecs = { ...prev };
        delete newRecs[professionalId];
        return newRecs;
      }
      return { ...prev, [professionalId]: recommendationData };
    });
  };

  const getDoctorLatLng = (doc: any, myLat: number, myLng: number) => {
    const locations: { lat: number; lng: number }[] = [];
    if (Array.isArray(doc?.address)) {
      doc.address.forEach((addr: any) => {
        const lat = addr?.lat ?? addr?.latitude;
        const lng = addr?.lng ?? addr?.longitude;
        if (lat != null && lng != null) locations.push({ lat: Number(lat), lng: Number(lng) });
      });
    }
    if (!locations.length) return null;
    let closest = locations[0];
    let minDist = distanceInKm(myLat, myLng, closest.lat, closest.lng);
    for (const loc of locations.slice(1)) {
      const d = distanceInKm(myLat, myLng, loc.lat, loc.lng);
      if (d < minDist) { minDist = d; closest = loc; }
    }
    return closest;
  };

  const distanceInKm = (lat1: number, lng1: number, lat2: number, lng2: number) => {
    const R = 6371;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };

  const sortAndFilterDoctors = (doctors: DoctorData[]): DoctorData[] => {
    let filtered = doctors;
    const normalizeGender = (g: any) => {
      if (g === 1 || g === "1") return 1;
      if (g === 2 || g === "2") return 2;
      return null;
    };
    if (genderFilter !== "all") {
      filtered = doctors.filter((doc) => {
        const g = normalizeGender(doc.gender);
        if (genderFilter === "male") return g === 1;
        if (genderFilter === "female") return g === 2;
        return true;
      });
    }

    // Always compute distance if location available
    if (userLocation) {
      filtered = filtered.map((doc) => {
        const p = getDoctorLatLng(doc, userLocation.lat, userLocation.lng);
        const dist = p ? distanceInKm(userLocation.lat, userLocation.lng, p.lat, p.lng) : 999999;
        return { ...doc, distance: dist };
      });
    }

    if (sortBy === "distance" && userLocation) {
      return filtered.sort((a, b) => (a.distance ?? 999999) - (b.distance ?? 999999));
    } else if (sortBy === "recommendations") {
      return filtered.sort((a, b) => {
        const af = (a as any).recCount?.friends || 0;
        const bf = (b as any).recCount?.friends || 0;
        if (af !== bf) return bf - af;
        const ac = (a as any).recCount?.colleagues || (a as any).recCount?.likeMe || 0;
        const bc = (b as any).recCount?.colleagues || (b as any).recCount?.likeMe || 0;
        if (ac !== bc) return bc - ac;
        return (b.recommendation_count || 0) - (a.recommendation_count || 0);
      });
    }
    return filtered;
  };

  const handleSend = async (text?: string) => {
    const msg = (text || input).trim();
    if (!msg) return;

    // Build history: if current messages contain options (showOptions flow),
    // include prior messages so the server can find the original symptom
    const prevMessages = messages;
    const hasOptionsFlow = prevMessages.some(m => m.role === "assistant" && m.options && m.options.length > 0);

    const userMessage: Message = { role: "user", content: msg };
    setMessages([userMessage]);
    setInput("");
    setIsTyping(true);

    try {
      let history: { role: "user" | "assistant"; content: string }[];

      if (hasOptionsFlow) {
        // Send full conversation: original symptom + bot options response + user selection
        history = prevMessages
          .map(m => ({ role: m.role, content: m.content }));
        history.push({ role: "user" as const, content: msg });
      } else {
        history = [{ role: "user" as const, content: msg }];
      }

      const response = await fetch(GPT_CHAT_API_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ history }),
      });
      const data = await response.json();

      let assistantMessage: Message;

      // Handle showOptions response with category choices
      if (data.showOptions && Array.isArray(data.options) && data.options.length > 0) {
        assistantMessage = {
          role: "assistant",
          content: data.response || "מצאתי כמה אפשרויות מתאימות עבורך. אנא בחר:",
          options: data.options,
        };
      } else if (data.ok && data.is_professional && data.data) {
        const doctors = [profileToDoctor(data.data as ProfessionalProfile)];
        assistantMessage = { role: "assistant", content: data.response || "נמצא רופא מתאים:", doctors };
      } else if (data.ok && Array.isArray(data.data)) {
        const doctors = data.data.map((prof: ProfessionalProfile) => profileToDoctor(prof));
        assistantMessage = { role: "assistant", content: data.response || `נמצאו ${doctors.length} רופאים מתאימים:`, doctors };
      } else if (data.results && Array.isArray(data.results) && data.results.length > 0) {
        const doctors = formatChatActionResults(data.results, data);
        assistantMessage = { role: "assistant", content: data.response || `נמצאו ${doctors.length} רופאים מתאימים:`, doctors };
      } else {
        assistantMessage = { role: "assistant", content: data.response || data.reply || "אנא ספר לי יותר על הבעיה הרפואית שלך" };
      }

      // If results contain doctors, clear history and show only results
      if (assistantMessage.doctors && assistantMessage.doctors.length > 0) {
        const argsTitle = data?.args?.mainSpecialty || data?.args?.cares || data?.mainSpecialty || data?.cares || msg;
        setCategoryTitle(argsTitle);
        setMessages([assistantMessage]);
      } else {
        setMessages([userMessage, assistantMessage]);
      }
    } catch {
      setMessages([userMessage, { role: "assistant", content: "שגיאה בשליחת הודעה" }]);
    } finally {
      setIsTyping(false);
    }
  };

  const formatChatActionResults = (results: any[], topLevelData?: any): DoctorData[] => {
    const args = topLevelData?.args || {};
    // explanation is at args level, build a map by category id
    const explanationMap = new Map<number, string>();
    if (args.clear_explanation && Array.isArray(args.clear_explanation)) {
      args.clear_explanation.forEach((e: any) => {
        if (e?.id && e?.reason) explanationMap.set(e.id, e.reason);
      });
    }

    return results.map((professional: any) => {
      const doctor: DoctorData = {
        id: professional.id,
        full_name: professional.full_name || "",
        title: professional.title || undefined,
        expertise: [],
        languages: [],
        recommendation_count: professional.recCount?.all || 0,
        positive_recommendations: 0,
        distance: professional.distance,
        address: professional.address || undefined,
        gender: professional.gender,
      };
      (doctor as any).recCount = professional.recCount;
      (doctor as any).company = professional.company;

      // category (array of {id, name}) → fall back to main_care
      const categoryArr = professional.category && Array.isArray(professional.category) ? professional.category : [];
      const mainCare = professional.main_care && Array.isArray(professional.main_care) ? professional.main_care : [];
      (doctor as any).categoryNames = categoryArr.map((c: any) => c.name);
      (doctor as any).mainCare = mainCare.map((c: any) => c.name);

      const mainSpecialty = args?.mainSpecialty || args?.cares || "";
      const specialty = args?.specialtyName;
      (doctor as any).mainSpecialty = mainSpecialty;
      (doctor as any).specialty = specialty;

      // Top-level specialty: mainSpecialty from args, fallback to cares from args
      const topSpec = args.mainSpecialty || args.cares || "";
      (doctor as any).topSpecialty = topSpec;

      // Match explanation by category id
      const matchedExplanation = categoryArr.length > 0 && explanationMap.size > 0
        ? explanationMap.get(categoryArr[0]?.id) || ""
        : "";
      (doctor as any).clear_explanation = matchedExplanation;

      if (professional.expertises && Array.isArray(professional.expertises)) {
        doctor.expertise = professional.expertises.map((e: any) => e.name);
      }
      if (categoryArr.length > 0) {
        const categoryNames = categoryArr.map((c: any) => c.name);
        doctor.expertise = [...(doctor.expertise as string[] || []), ...categoryNames];
      }
      if (professional.address && Array.isArray(professional.address)) {
        doctor.address = professional.address.map((addr: any) => ({
          street: addr.street ?? null, house_number: addr.house_number ?? null,
          city: addr.city ?? null, lat: addr.lat ?? 0, lng: addr.lng ?? 0,
        })).filter((a: any) => a.street || a.city || a.lat || a.lng);
      }
      if (professional.myRecommendation?.analysis) {
        const analysis = professional.myRecommendation.analysis;
        if (analysis.sentiment) { doctor.sentiment = analysis.sentiment; doctor.sentiment_confidence = analysis.sentiment_confidence; }
        if (analysis.doctor_metrics) {
          const metrics = analysis.doctor_metrics;
          const nums = Object.entries(metrics).filter(([k, v]) => k !== "cost" && typeof v === "number").map(([, v]) => v as number);
          if (nums.length > 0) doctor.average_rating = nums.reduce((a, b) => a + b, 0) / nums.length;
          doctor.doctor_metrics = metrics;
        }
      }
      if (professional.recCount) {
        doctor.positive_recommendations = professional.recCount.friends || professional.recCount.colleagues || professional.recCount.likeMe || 0;
      }
      return doctor;
    });
  };

  const filtersRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    // If latest message has doctors, scroll to top of results (filters area)
    const lastMsg = messages[messages.length - 1];
    if (lastMsg?.doctors && lastMsg.doctors.length > 0) {
      filtersRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    } else {
      messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
    }
  }, [messages, isTyping]);

  return (
    <div className="min-h-[calc(100vh-80px)] flex flex-col pt-[10px] px-0">
      <AnimatePresence mode="wait">
        {!hasMessages ? (
          /* ===== HERO / LANDING STATE ===== */
          <motion.div
            key="hero"
            initial={{ opacity: 1 }}
            exit={{ opacity: 0, y: -30 }}
            transition={{ duration: 0.3 }}
            className="flex-1 flex flex-col items-center justify-center px-4 pb-0 bg-background max-w-[1000px] mx-auto w-full"
          >
            {/* Logo */}
            <motion.div
              initial={{ opacity: 0, y: -20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.5 }}
              className="flex flex-col items-center mb-8"
            >
              <img src={logoIcon} alt="דוקטוריטה" className="h-10 mb-2" />
            </motion.div>

            {/* Main Heading */}
            <motion.h2
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.15, duration: 0.5 }}
              className="text-2xl md:text-4xl font-bold text-foreground text-center mb-8 leading-tight"
            >
              חיפוש רופא.ה
              <br />
              ומטפל.ת
            </motion.h2>

            {/* Search Bar */}
            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.25, duration: 0.5 }}
              className="w-full max-w-xl mb-6"
            >
              <div className="flex items-center bg-background border-2 border-border rounded-full shadow-lg overflow-hidden pr-5 pl-1 py-1 focus-within:border-border transition-colors">
                <input
                  type="text"
                  value={input}
                  onChange={(e) => setInput(e.target.value)}
                  placeholder="שם, טיפול, מומחיות, בעייה רפואית"
                  className="flex-1 bg-transparent text-foreground placeholder:text-muted-foreground text-base outline-none border-none py-3"
                  dir="rtl"
                  onKeyDown={(e) => {
                    if (e.key === "Enter") {
                      e.preventDefault();
                      handleSend();
                    }
                  }}
                />
                {input.trim() ? (
                  <>
                    <button
                      onClick={() => setInput("")}
                      className="w-8 h-8 rounded-full bg-muted flex items-center justify-center text-muted-foreground hover:bg-muted/80 transition-colors shrink-0 mx-1"
                    >
                      <X className="w-4 h-4" />
                    </button>
                    <button
                      onClick={() => handleSend()}
                      disabled={isTyping}
                      className="flex items-center gap-2 px-4 py-2 rounded-full border border-primary/30 bg-background text-foreground text-sm font-medium hover:bg-accent transition-colors shrink-0 disabled:opacity-40"
                    >
                      <span>AI חיפוש</span>
                      <div className="w-8 h-8 rounded-full bg-primary flex items-center justify-center">
                        <Send className="w-4 h-4 text-primary-foreground -rotate-90" />
                      </div>
                    </button>
                  </>
                ) : (
                  <button
                    onClick={() => handleSend()}
                    disabled={!input.trim() || isTyping}
                    className="w-12 h-12 rounded-full bg-primary flex items-center justify-center text-primary-foreground hover:opacity-90 transition-opacity disabled:opacity-40 shrink-0"
                  >
                    <Send className="w-5 h-5 -rotate-90" />
                  </button>
                )}
              </div>
            </motion.div>

            {/* Subtitle */}
            <motion.p
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ delay: 0.35, duration: 0.5 }}
              className="text-muted-foreground text-center text-sm md:text-base max-w-lg mb-10 leading-relaxed"
            >
              המלצות מחברים, אנשים שהמליצו כמוך, אנשים
              <br className="hidden md:block" />
              מהאזור, המלצות שקרובות אלי ואוטוריטות
            </motion.p>

            {/* Specialty Suggestions */}
            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.45, duration: 0.5 }}
              className="w-full max-w-2xl"
            >
              <p className="text-muted-foreground text-center text-sm mb-4">הצעות לחיפוש:</p>

              {/* Desktop: Grid / Mobile: Horizontal Scroll */}
              <div
                ref={specialtiesRef}
                className="flex md:flex-wrap md:justify-center gap-3 overflow-x-auto md:overflow-visible pb-2 px-2 snap-x snap-mandatory scrollbar-hide"
                style={{ scrollbarWidth: "none", msOverflowStyle: "none" }}
              >
                {SPECIALTIES.map((spec) => (
                  <button
                    key={spec}
                    onClick={() => handleSend(spec)}
                    className="shrink-0 snap-start px-5 py-3 rounded-xl border border-border bg-background text-foreground text-sm font-medium hover:bg-accent hover:shadow-md transition-all duration-200 active:scale-95"
                  >
                    {spec}
                  </button>
                ))}
              </div>
            </motion.div>

            {/* Decorative curve at the bottom-left */}
            <div className="w-full flex justify-end mt-auto">
              <img
                src={heroBgCurve}
                alt=""
                className="pointer-events-none select-none max-w-[400px]"
              />
            </div>
          </motion.div>
        ) : (
          /* ===== CHAT STATE ===== */
          <motion.div
            key="chat"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.3 }}
            className="flex-1 flex flex-col"
          >
            {/* Chat Input - Top */}
            <div className="sticky top-0 z-10 bg-background border-b border-border p-2 md:p-3">
              <div className="max-w-3xl mx-auto flex items-center gap-2 pr-12 md:pr-0">
                <div className="flex-1 flex items-center bg-background border-2 border-border rounded-full overflow-hidden pr-3 md:pr-4 pl-1 py-1 focus-within:border-border transition-colors">
                  <input
                    type="text"
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    placeholder="שם, טיפול, מומחיות..."
                    className="flex-1 bg-transparent text-foreground placeholder:text-muted-foreground text-xs md:text-sm outline-none border-none py-2"
                    dir="rtl"
                    onKeyDown={(e) => {
                      if (e.key === "Enter") { e.preventDefault(); handleSend(); }
                    }}
                  />
                  <button
                    onClick={() => handleSend()}
                    disabled={!input.trim() || isTyping}
                    className="w-8 h-8 md:w-10 md:h-10 rounded-full bg-primary flex items-center justify-center text-primary-foreground hover:opacity-90 transition-opacity disabled:opacity-40 shrink-0"
                  >
                    <Send className="w-3.5 h-3.5 md:w-4 md:h-4 -rotate-90" />
                  </button>
                </div>
              </div>
            </div>

            {/* Chat Messages */}
            <div className="flex-1 overflow-y-auto p-2 md:p-6">
              <div className="max-w-3xl mx-auto space-y-4">
                {messages.map((msg, index) => (
                  <div key={index} className="space-y-3" dir="rtl">
                    {/* User message bubble */}
                    {msg.role === "user" && (
                      <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3 }}
                        className="flex justify-start"
                      >
                        <div className="rounded-full px-5 py-3 bg-chat-bubble text-chat-bubble-foreground max-w-[75%]">
                          <p className="text-sm whitespace-pre-wrap break-words">{msg.content}</p>
                        </div>
                      </motion.div>
                    )}

                    {/* Assistant text response with options */}
                    {msg.role === "assistant" && msg.options && msg.options.length > 0 && (
                      <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3 }}
                        className="flex flex-col items-start gap-3"
                      >
                        <p className="text-sm font-bold text-foreground">דוקטוריטה:</p>
                        <p className="text-sm text-foreground">{msg.content}</p>
                        {msg.options.map((opt) => (
                          <div key={opt.id} className="w-full flex flex-col items-start gap-1">
                            <button
                              onClick={() => handleSend(opt.name)}
                              className="flex items-center gap-2 px-5 py-2.5 rounded-full border border-border bg-background text-foreground text-sm font-medium hover:bg-accent transition-colors"
                            >
                              <ChevronLeft className="w-4 h-4" />
                              <span>{opt.name}</span>
                            </button>
                            {opt.description && (
                              <p className="text-sm text-foreground max-w-[85%] text-right leading-relaxed">{opt.description}</p>
                            )}
                          </div>
                        ))}
                      </motion.div>
                    )}

                    {/* Assistant text response (no doctors, no options) */}
                    {msg.role === "assistant" && (!msg.doctors || msg.doctors.length === 0) && (!msg.options || msg.options.length === 0) && (
                      <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3 }}
                        className="flex flex-col items-start gap-1"
                      >
                        <p className="text-sm font-bold text-foreground">דוקטוריטה:</p>
                        <p className="text-sm whitespace-pre-wrap break-words text-foreground text-right">{msg.content}</p>
                      </motion.div>
                    )}

                    {/* Doctor Cards - results only, no chat history */}
                    {msg.role === "assistant" && msg.doctors && msg.doctors.length > 0 && (
                      <div className="flex flex-col items-center w-full">
                        <div ref={filtersRef} className="w-full max-w-[600px] mb-4">
                          <DoctorFilters sortBy={sortBy} setSortBy={setSortBy} genderFilter={genderFilter} setGenderFilter={setGenderFilter} memberLocation={userLocation} categoryTitle={categoryTitle} />
                        </div>
                        <div className="space-y-3 w-full max-w-[600px]">
                          {sortAndFilterDoctors(msg.doctors).map((doctor, idx) => (
                            <div key={doctor.id}>
                              <DoctorCard doctor={doctor} index={idx} userRecommendation={userRecommendations[doctor.id]} onRecommendationUpdate={handleRecommendationUpdate} userLocation={userLocation} />
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                ))}

                {isTyping && (
                  <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} className="w-full" dir="rtl">
                    <TypingIndicator />
                  </motion.div>
                )}
                <div ref={messagesEndRef} />
              </div>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
