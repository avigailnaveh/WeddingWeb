import React, { useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { motion } from "framer-motion";
import { Menu, X, ShieldCheck, MessageSquare, Stethoscope } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useProfessional } from "@/hooks/useProfessional";

export function Layout({ children }: { children: React.ReactNode }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();
  const { isProfessional, loading } = useProfessional();

  const baseNavItems = [
    { path: "/crm", label: "CRM - ניהול המלצות", icon: ShieldCheck },
    { path: "/chat", label: "צ'אט", icon: MessageSquare },
  ];

  const navItems = isProfessional && !loading
    ? [
        ...baseNavItems,
        { path: "/doctor-dashboard", label: "דשבורד רופא", icon: Stethoscope },
      ]
    : baseNavItems;

  const isActive = (path: string) => location.pathname === path;

  return (
    <div className="min-h-screen bg-background">
      {/* Hamburger Button - fixed top-left (RTL: visually top-right) */}
      <button
        onClick={() => setSidebarOpen(!sidebarOpen)}
        className="fixed top-4 right-4 z-50 w-10 h-10 flex items-center justify-center rounded-xl text-foreground hover:bg-accent transition-colors"
      >
        {sidebarOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
      </button>

      {/* Mobile Sidebar */}
      {sidebarOpen && (
        <motion.div
          initial={{ opacity: 0, x: -300 }}
          animate={{ opacity: 1, x: 0 }}
          exit={{ opacity: 0, x: -300 }}
          transition={{ duration: 0.2 }}
          className="fixed inset-0 z-40"
        >
          <div
            className="absolute inset-0 bg-black/20 backdrop-blur-sm"
            onClick={() => setSidebarOpen(false)}
          />
          <div className="absolute right-0 top-0 h-full w-64 bg-background shadow-xl">
            <div className="pt-20 px-4 space-y-2">
              {navItems.map((item) => {
                const Icon = item.icon;
                const active = isActive(item.path);
                return (
                  <Button
                    key={item.path}
                    asChild
                    variant={active ? "default" : "ghost"}
                    className="w-full justify-start rounded-xl"
                    onClick={() => setSidebarOpen(false)}
                  >
                    <Link to={item.path} className="gap-2 inline-flex items-center">
                      <Icon className="h-4 w-4" />
                      {item.label}
                    </Link>
                  </Button>
                );
              })}
            </div>
          </div>
        </motion.div>
      )}

      {/* Main Content */}
      <main>{children}</main>
    </div>
  );
}