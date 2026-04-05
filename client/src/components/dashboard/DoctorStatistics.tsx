import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { MessageSquare, Eye, Heart, Coins, Stethoscope, Clock, Calendar } from "lucide-react";
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, BarChart, Bar, RadarChart, Radar, PolarGrid, PolarAngleAxis, PolarRadiusAxis } from "recharts";
import { ProfessionalStatistics } from "@/api/professional";

interface DoctorStatisticsProps {
  statistics: ProfessionalStatistics | null;
  loading: boolean;
}

const DoctorStatistics = ({ statistics, loading }: DoctorStatisticsProps) => {
  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <p className="text-muted-foreground">טוען סטטיסטיקות...</p>
      </div>
    );
  }

  if (!statistics) {
    return (
      <div className="flex items-center justify-center py-12">
        <p className="text-muted-foreground">לא נמצאו סטטיסטיקות</p>
      </div>
    );
  }

  // שימוש במטריקות אמיתיות מהדאטאבייס
  const categoryStats = [
    { 
      name: "מקצועיות", 
      value: statistics.category_metrics?.professionalism || 0, 
      icon: Stethoscope, 
      color: "hsl(187, 65%, 42%)" 
    },
    { 
      name: "אמפתיה", 
      value: statistics.category_metrics?.empathy || 0, 
      icon: Heart, 
      color: "hsl(0, 70%, 60%)" 
    },
    { 
      name: "זמינות", 
      value: statistics.category_metrics?.availability || 0, 
      icon: Clock, 
      color: "hsl(200, 70%, 50%)" 
    },
    { 
      name: "עלות סבירה", 
      value: statistics.category_metrics?.cost || 0, 
      icon: Coins, 
      color: "hsl(45, 93%, 47%)" 
    },
  ];

  // יצירת נתוני radar chart מהמטריקות האמיתיות
  const recommendationCategories = [
    { category: "מקצועיות", mentions: statistics.category_metrics?.professionalism || 0, fullMark: Math.max(...Object.values(statistics.category_metrics || {})) || 10 },
    { category: "אמפתיה", mentions: statistics.category_metrics?.empathy || 0, fullMark: Math.max(...Object.values(statistics.category_metrics || {})) || 10 },
    { category: "זמינות", mentions: statistics.category_metrics?.availability || 0, fullMark: Math.max(...Object.values(statistics.category_metrics || {})) || 10 },
    { category: "עלות סבירה", mentions: statistics.category_metrics?.cost || 0, fullMark: Math.max(...Object.values(statistics.category_metrics || {})) || 10 },
    { category: "הסבר ברור", mentions: statistics.category_metrics?.clear_explanation || 0, fullMark: Math.max(...Object.values(statistics.category_metrics || {})) || 10 },
    { category: "סבלנות", mentions: statistics.category_metrics?.patience || 0, fullMark: Math.max(...Object.values(statistics.category_metrics || {})) || 10 },
  ];

  const stats = [
    {
      title: "סה״כ המלצות",
      value: statistics.total_recommendations.toString(),
      change: statistics.monthly_change,
      changeLabel: "החודש",
      icon: MessageSquare,
      color: "text-primary",
      bgColor: "bg-primary/10",
    },
    {
      title: "צפיות בפרופיל",
      value: statistics.profile_views.toLocaleString(),
      change: statistics.views_change,
      changeLabel: "החודש",
      icon: Eye,
      color: "text-accent",
      bgColor: "bg-accent/10",
    },
  ];

  return (
    <div className="space-y-6">
      {/* Stats Grid */}
      <div className="grid grid-cols-2 gap-4">
        {stats.map((stat) => (
          <Card key={stat.title} className="shadow-card border-0">
            <CardContent className="p-4 md:p-6">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">{stat.title}</p>
                  <p className="text-2xl md:text-3xl font-bold text-foreground mt-1">
                    {stat.value}
                  </p>
                  <div className="flex items-center gap-1 mt-2">
                    <span className="text-xs text-success font-medium">{stat.change}</span>
                    <span className="text-xs text-muted-foreground">{stat.changeLabel}</span>
                  </div>
                </div>
                {/* <div className={`p-2.5 rounded-lg ${stat.bgColor}`}>
                  <stat.icon className={`w-5 h-5 ${stat.color}`} />
                </div> */}
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Category Scores */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {categoryStats.map((cat) => (
          <Card key={cat.name} className="shadow-card border-0">
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div 
                  className="p-2 rounded-lg"
                  style={{ backgroundColor: `${cat.color}20` }}
                >
                  <cat.icon className="w-5 h-5" style={{ color: cat.color }} />
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">{cat.name}</p>
                  <p className="text-xl font-bold text-foreground">{cat.value}</p>
                </div>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Charts Row */}
      <div className="grid lg:grid-cols-2 gap-6">
        {/* Reviews Over Time */}
        <Card className="shadow-card border-0">
          <CardHeader>
            <CardTitle className="text-lg flex items-center gap-2">
              <Calendar className="w-5 h-5 text-primary" />
              המלצות לאורך זמן
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={statistics.reviews_over_time}>
                  <defs>
                    <linearGradient id="colorReviews" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="hsl(187, 65%, 42%)" stopOpacity={0.3} />
                      <stop offset="95%" stopColor="hsl(187, 65%, 42%)" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="hsl(210, 20%, 90%)" />
                  <XAxis 
                    dataKey="month" 
                    axisLine={false}
                    tickLine={false}
                    tick={{ fill: 'hsl(210, 15%, 45%)', fontSize: 12 }}
                  />
                  <YAxis 
                    axisLine={false}
                    tickLine={false}
                    tick={{ fill: 'hsl(210, 15%, 45%)', fontSize: 12 }}
                  />
                  <Tooltip 
                    contentStyle={{
                      backgroundColor: 'hsl(0, 0%, 100%)',
                      border: 'none',
                      borderRadius: '8px',
                      boxShadow: '0 4px 20px -4px rgba(0,0,0,0.15)',
                    }}
                    formatter={(value: any) => [`${value} המלצות`, '']}
                  />
                  <Area
                    type="monotone"
                    dataKey="reviews"
                    stroke="hsl(187, 65%, 42%)"
                    strokeWidth={2}
                    fillOpacity={1}
                    fill="url(#colorReviews)"
                  />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        {/* Radar Chart - Category Analysis */}
        <Card className="shadow-card border-0">
          <CardHeader>
            <CardTitle className="text-lg flex items-center gap-2">
              <Heart className="w-5 h-5 text-destructive" />
              ניתוח המלצות לפי קטגוריה
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <RadarChart data={recommendationCategories}>
                  <PolarGrid stroke="hsl(210, 20%, 90%)" />
                  <PolarAngleAxis 
                    dataKey="category" 
                    tick={{ fill: 'hsl(210, 15%, 45%)', fontSize: 11 }}
                  />
                  <PolarRadiusAxis 
                    angle={90} 
                    domain={[0, 'dataMax']}
                    tick={{ fill: 'hsl(210, 15%, 45%)', fontSize: 10 }}
                  />
                  <Radar
                    name="מספר אזכורים"
                    dataKey="mentions"
                    stroke="hsl(187, 65%, 42%)"
                    fill="hsl(187, 65%, 42%)"
                    fillOpacity={0.3}
                    strokeWidth={2}
                  />
                  <Tooltip 
                    contentStyle={{
                      backgroundColor: 'hsl(0, 0%, 100%)',
                      border: 'none',
                      borderRadius: '8px',
                      boxShadow: '0 4px 20px -4px rgba(0,0,0,0.15)',
                    }}
                    formatter={(value: any) => [`${value}`, 'אזכורים']}
                  />
                </RadarChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Category Bar Chart */}
      <Card className="shadow-card border-0">
        <CardHeader>
          <CardTitle dir="rtl" className="text-lg flex items-center gap-2 text-right">
            <Stethoscope className="w-5 h-5 text-primary" />
            השוואת קטגוריות בהמלצות
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={recommendationCategories}>
                <CartesianGrid strokeDasharray="3 3" stroke="hsl(210, 20%, 90%)" />
                <XAxis 
                  dataKey="category" 
                  axisLine={false}
                  tickLine={false}
                  tick={{ fill: 'hsl(210, 15%, 45%)', fontSize: 11 }}
                />
                <YAxis 
                  axisLine={false}
                  tickLine={false}
                  tick={{ fill: 'hsl(210, 15%, 45%)', fontSize: 12 }}
                  domain={[0, 'dataMax']}
                />
                <Tooltip 
                  contentStyle={{
                    backgroundColor: 'hsl(0, 0%, 100%)',
                    border: 'none',
                    borderRadius: '8px',
                    boxShadow: '0 4px 20px -4px rgba(0,0,0,0.15)',
                  }}
                  formatter={(value: any) => [`${value}`, 'אזכורים']}
                />
                <Bar 
                  dataKey="mentions" 
                  fill="hsl(187, 65%, 42%)"
                  radius={[4, 4, 0, 0]}
                />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default DoctorStatistics;