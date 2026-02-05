<?php
/**
 * SupportAI Class
 * Auto-response ve FAQ yönetimi
 */
class SupportAI {
    private $conn;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }
    
    /**
     * Mesaja göre otomatik yanıt bul
     */
    public function getAutoResponse($message) {
        $message = strtolower(trim($message));
        
        // Tüm auto response'ları al (önceliğe göre)
        $stmt = $this->conn->prepare("SELECT auto_id, trigger_keywords, response_message, related_faq_id FROM AutoResponses WHERE is_active = TRUE ORDER BY priority DESC");
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($auto = $result->fetch_assoc()) {
            $keywords = explode(',', strtolower($auto['trigger_keywords']));
            
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (strpos($message, $keyword) !== false) {
                    $stmt->close();
                    return [
                        'found' => true,
                        'message' => $auto['response_message'],
                        'faq_id' => $auto['related_faq_id']
                    ];
                }
            }
        }
        
        $stmt->close();
        return ['found' => false];
    }
    
    /**
     * FAQ ara
     */
    public function searchFAQ($query, $category_id = null, $limit = 5) {
        $query = strtolower(trim($query));
        $words = explode(' ', $query);
        
        $sql = "SELECT faq_id, question, answer, category_id FROM SupportFAQ WHERE is_active = TRUE";
        
        if ($category_id) {
            $sql .= " AND category_id = " . intval($category_id);
        }
        
        // Kelime bazlı arama
        if (!empty($words)) {
            $sql .= " AND (";
            $conditions = [];
            foreach ($words as $word) {
                if (strlen($word) > 2) {
                    $word = $this->conn->real_escape_string($word);
                    $conditions[] = "(LOWER(question) LIKE '%$word%' OR LOWER(keywords) LIKE '%$word%' OR LOWER(answer) LIKE '%$word%')";
                }
            }
            $sql .= implode(' OR ', $conditions) . ")";
        }
        
        $sql .= " ORDER BY order_priority DESC, view_count DESC LIMIT " . intval($limit);
        
        $result = $this->conn->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Hızlı yanıtları getir
     */
    public function getQuickReplies($for_role = 'all', $category_id = null) {
        $sql = "SELECT reply_id, title, message, category_id FROM QuickReplies WHERE is_active = TRUE AND (for_role = ? OR for_role = 'all')";
        
        if ($category_id) {
            $sql .= " AND category_id = " . intval($category_id);
        }
        
        $sql .= " ORDER BY usage_count DESC, reply_id ASC LIMIT 10";
        // Graceful fallback if optional table doesn't exist yet.
        $existsCount = 0;
        $tableCheck = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'QuickReplies'");
        if ($tableCheck) {
            $tableCheck->execute();
            $tableCheck->bind_result($existsCount);
            $tableCheck->fetch();
            $tableCheck->close();
            if ((int)$existsCount === 0) {
                return [];
            }
        }
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $for_role);
        $stmt->execute();
        $result = $stmt->get_result();
        $replies = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $replies;
    }
    
    /**
     * Hızlı yanıt kullan
     */
    public function useQuickReply($reply_id) {
        $stmt = $this->conn->prepare("SELECT message FROM QuickReplies WHERE reply_id = ? AND is_active = TRUE");
        $stmt->bind_param("i", $reply_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $reply = $result->fetch_assoc();
        $stmt->close();
        
        if ($reply) {
            // Kullanım sayısını artır
            $this->conn->query("UPDATE QuickReplies SET usage_count = usage_count + 1 WHERE reply_id = $reply_id");
            return $reply['message'];
        }
        
        return null;
    }
    
    /**
     * FAQ görüntüleme sayısını artır
     */
    public function incrementFAQView($faq_id) {
        $this->conn->query("UPDATE SupportFAQ SET view_count = view_count + 1 WHERE faq_id = " . intval($faq_id));
    }
    
    /**
     * Popüler FAQ'leri getir
     */
    public function getPopularFAQs($limit = 5, $category_id = null) {
        $sql = "SELECT faq_id, question, answer FROM SupportFAQ WHERE is_active = TRUE";
        
        if ($category_id) {
            $sql .= " AND category_id = " . intval($category_id);
        }
        
        $sql .= " ORDER BY view_count DESC, order_priority DESC LIMIT " . intval($limit);
        
        $result = $this->conn->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * İlk mesajda otomatik yanıt ver
     */
    public function handleInitialMessage($ticket_id, $message, $user_role = 'customer') {
        // Auto-response kontrol et
        $autoResponse = $this->getAutoResponse($message);
        
        if ($autoResponse['found']) {
            // Otomatik yanıt ver
            $stmt = $this->conn->prepare("INSERT INTO SupportMessages (ticket_id, sender_id, message, is_operator, created_at) VALUES (?, 1, ?, TRUE, NOW())");
            $bot_message = "🤖 " . $autoResponse['message'];
            $stmt->bind_param("is", $ticket_id, $bot_message);
            $stmt->execute();
            $stmt->close();
            
            // Ticket'ı güncelle
            $this->conn->query("UPDATE SupportTickets SET auto_response_given = TRUE WHERE ticket_id = $ticket_id");
            
            return true;
        }
        
        // FAQ öner
        $faqs = $this->searchFAQ($message, null, 3);
        if (!empty($faqs)) {
            $faq_message = "🤖 Size yardımcı olabilecek bilgiler:\n\n";
            foreach ($faqs as $faq) {
                $faq_message .= "❓ " . $faq['question'] . "\n💡 " . substr($faq['answer'], 0, 100) . "...\n\n";
            }
            $faq_message .= "Bir operatör en kısa sürede size dönüş yapacak.";
            
            $stmt = $this->conn->prepare("INSERT INTO SupportMessages (ticket_id, sender_id, message, is_operator, created_at) VALUES (?, 1, ?, TRUE, NOW())");
            $stmt->bind_param("is", $ticket_id, $faq_message);
            $stmt->execute();
            $stmt->close();
            
            $this->conn->query("UPDATE SupportTickets SET auto_response_given = TRUE WHERE ticket_id = $ticket_id");
            
            return true;
        }
        
        return false;
    }
}
?>
