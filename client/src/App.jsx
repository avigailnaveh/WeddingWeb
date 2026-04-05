import { BrowserRouter, Routes, Route } from "react-router-dom";
import { Layout } from "@/components/Layout";
import SubmitPage from "@/pages/SubmitPage";
import CRMPage from "@/pages/CRMPage";
import ChatPage from "@/pages/ChatPage";
import DoctorDashboard from "@/pages/DoctorDashboard";

export default function App() {
  return (
    <BrowserRouter>
      <Layout>
        <Routes>
          <Route path="/" element={<SubmitPage />} />
          <Route path="/submit" element={<SubmitPage />} />
          <Route path="/crm" element={<CRMPage />} />
          <Route path="/chat" element={<ChatPage />} />
          <Route path="/doctor-dashboard" element={<DoctorDashboard />} />
        </Routes>
      </Layout>
    </BrowserRouter>
  );
}