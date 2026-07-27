-- ---------------------------------------------------------------------------
-- Krishna Reminder — seed data (plans, categories, message templates, content)
-- Safe to re-run: every insert is INSERT IGNORE / ON DUPLICATE KEY UPDATE free.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ------------------------------------------------------------------- Plans

INSERT IGNORE INTO `plans`
(`code`,`name`,`name_gu`,`name_hi`,`description`,`price`,`currency`,`duration_days`,`trial_days`,
 `max_reminders_month`,`max_ai_messages_month`,`max_ai_tokens_month`,`max_devices`,`max_staff`,
 `call_reminders`,`google_sync`,`api_access`,`full_reports`,`is_active`,`is_default`,`sort_order`)
VALUES
('trial','Free Trial','ફ્રી ટ્રાયલ','फ्री ट्रायल','7 days to try everything.',0.00,'INR',7,7,
 30,30,60000,1,0,1,0,0,0,1,1,1),
('basic','Basic','બેઝિક','बेसिक','For personal use.',149.00,'INR',30,0,
 200,200,300000,1,0,1,0,0,0,1,0,2),
('pro','Pro','પ્રો','प्रो','For busy professionals and small shops.',299.00,'INR',30,0,
 -1,1000,1200000,3,2,1,1,0,1,1,0,3),
('business','Business','બિઝનેસ','बिजनेस','For teams and offices.',799.00,'INR',30,0,
 -1,3000,3500000,10,10,1,1,1,1,1,0,4);

-- -------------------------------------------------------- System categories

INSERT IGNORE INTO `categories` (`id`,`user_id`,`code`,`name_gu`,`name_hi`,`name_en`,`icon`,`color`,`sort_order`) VALUES
(1,NULL,'work','ઓફિસ / કામ','ऑफिस / काम','Work','briefcase','#1B3A6B',1),
(2,NULL,'payment','પૈસા / ચુકવણી','पैसे / भुगतान','Payment','wallet','#1E8E5A',2),
(3,NULL,'personal','અંગત','व्यक्तिगत','Personal','user','#7C4DFF',3),
(4,NULL,'health','દવા / આરોગ્ય','दवा / स्वास्थ्य','Health','heart-pulse','#C0392B',4),
(5,NULL,'meeting','મીટિંગ','मीटिंग','Meeting','users','#F2B33D',5),
(6,NULL,'bill','બિલ','बिल','Bill','receipt','#E67E22',6),
(7,NULL,'birthday','જન્મદિવસ','जन्मदिन','Birthday','cake','#E84393',7),
(8,NULL,'shop','દુકાન','दुकान','Shop','store','#00838F',8),
(9,NULL,'family','પરિવાર','परिवार','Family','home','#8D6E63',9),
(10,NULL,'other','બીજું','अन्य','Other','tag','#607D8B',10);

-- ------------------------------------------------------------- Core settings

INSERT IGNORE INTO `settings` (`setting_key`,`setting_value`,`setting_group`,`is_encrypted`) VALUES
('site_name','Krishna Reminder','general',0),
('company_name','AK Computer','general',0),
('company_address','Shreeji Shopping Center, near City Palace Hotel, Dwarka, Gujarat','general',0),
('company_phone','+91 99781 23146','general',0),
('company_email','support@akdwk.in','general',0),
('company_gstin','','general',0),
('support_whatsapp','919978123146','general',0),
('default_language','en','general',0),
('default_timezone','Asia/Kolkata','general',0),
('currency','INR','general',0),
('maintenance_mode','0','general',0),
('asset_version','1.0.0','general',0),
('app_version','1.0.0','general',0),

('wa_endpoint','https://bulk.akdwk.in','whatsapp',0),
('wa_api_key','','whatsapp',1),
('wa_session_id','','whatsapp',1),
('wa_sender_number','919978123146','whatsapp',0),
('wa_rate_per_minute','30','whatsapp',0),
('wa_max_retries','3','whatsapp',0),
('wa_enabled','1','whatsapp',0),
('wa_invite_unknown','1','whatsapp',0),
('wa_invite_cooldown_days','7','whatsapp',0),

-- Second provider: Meta WhatsApp Cloud API. Both can be configured at once;
-- wa_provider chooses which is tried first and wa_failover allows the other.
('wa_provider','bulk','whatsapp',0),
('wa_failover','1','whatsapp',0),
('wa_cloud_token','','whatsapp',1),
('wa_cloud_app_secret','','whatsapp',1),
('wa_cloud_phone_id','','whatsapp',0),
('wa_cloud_business_id','','whatsapp',0),
('wa_cloud_api_version','v23.0','whatsapp',0),
('wa_cloud_verify_token','','whatsapp',0),
('wa_cloud_template_name','','whatsapp',0),
('wa_cloud_template_lang','gu','whatsapp',0),

('tg_enabled','0','telegram',0),
('tg_bot_token','','telegram',1),
('tg_bot_username','','telegram',0),
('tg_webhook_secret','','telegram',0),
('tg_send_reminders','1','telegram',0),

('gemini_api_key','','ai',1),
('gemini_api_key_2','','ai',1),
('gemini_model','gemini-2.0-flash','ai',0),
('gemini_temperature','0.2','ai',0),
('gemini_max_tokens','1024','ai',0),
('gemini_enabled','1','ai',0),
('ai_monthly_budget_tokens','20000000','ai',0),
('ai_cost_per_1k_input','0.000075','ai',0),
('ai_cost_per_1k_output','0.0003','ai',0),
('ai_fallback_enabled','1','ai',0),
('ai_cache_hours','24','ai',0),
('ai_prompt_template','','ai',0),

('fcm_server_key','','push',1),
('fcm_project_id','','push',0),
('fcm_service_account','','push',1),

('google_client_id','','google',0),
('google_client_secret','','google',1),
('google_enabled','0','google',0),

('github_owner','akshaykananidwk','update',0),
('github_repo','reminder.akdwk.in','update',0),
('github_branch','main','update',0),
('github_token','','update',1),
('update_auto_check','1','update',0),
('backup_retention_days','14','update',0),

('ga4_id','','seo',0),
('search_console_verification','','seo',0),
('meta_description','WhatsApp પર મેસેજ કરો, સમય થતાં મોબાઇલમાં કોલ આવશે. કૃષ્ણ રિમાઇન્ડર — ગુજરાતી AI રિમાઇન્ડર એપ.','seo',0),
('apk_release_url','https://github.com/akshaykananidwk/reminder.akdwk.in/releases/latest','seo',0),

('alert_admin_number','919978123146','alerts',0),
('cron_alert_minutes','15','alerts',0),
('referral_commission_percent','20','billing',0),
('gst_rate','18','billing',0),
('invoice_prefix','KR','billing',0),
('grace_days','3','billing',0);

-- --------------------------------------------------------- Message templates

INSERT IGNORE INTO `templates` (`template_key`,`lang`,`channel`,`body`,`variables`) VALUES
-- OTP
('otp','gu','whatsapp','🔐 *કૃષ્ણ રિમાઇન્ડર*\nતમારો વેરિફિકેશન કોડ: *{code}*\n\nઆ કોડ 5 મિનિટ સુધી ચાલશે. કોઈને આપશો નહીં.\n\n🙏 જય શ્રી કૃષ્ણ','code'),
('otp','hi','whatsapp','🔐 *कृष्णा रिमाइंडर*\nआपका वेरिफिकेशन कोड: *{code}*\n\nयह कोड 5 मिनट तक वैध है। किसी को न बताएं।\n\n🙏 जय श्री कृष्ण','code'),
('otp','en','whatsapp','🔐 *Krishna Reminder*\nYour verification code is *{code}*\n\nValid for 5 minutes. Never share it with anyone.\n\n🙏 Jay Shree Krishna','code'),

-- Welcome
('welcome','gu','whatsapp','🙏 *નમસ્તે {name}!*\nકૃષ્ણ રિમાઇન્ડરમાં આપનું સ્વાગત છે.\n\nહવે તમે અહીં જ મેસેજ કરીને કામ યાદ રાખી શકો છો. જેમ કે:\n_"કાલે સવારે 10 વાગ્યે બેંક જવાનું છે"_\n\nસમય થતાં તમારા મોબાઇલમાં કોલ આવશે અને અવાજમાં યાદ કરાવશે. 📞\n\nમદદ માટે લખો: *મદદ*','name'),
('welcome','hi','whatsapp','🙏 *नमस्ते {name}!*\nकृष्णा रिमाइंडर में आपका स्वागत है।\n\nअब आप यहीं मैसेज करके काम याद रख सकते हैं, जैसे:\n_"कल सुबह 10 बजे बैंक जाना है"_\n\nसमय होते ही आपके मोबाइल पर कॉल आएगा। 📞\n\nमदद के लिए लिखें: *मदद*','name'),
('welcome','en','whatsapp','🙏 *Welcome {name}!*\nKrishna Reminder is ready.\n\nJust message me here, for example:\n_"Call the bank tomorrow at 10 am"_\n\nYour phone will ring at the right time and speak the reminder out loud. 📞\n\nType *HELP* for commands.','name'),

-- Reminder created
('reminder_created','gu','whatsapp','✅ *યાદ રાખ્યું!* [{code}]\n📌 {title}\n🕙 {time}\n📞 સમય થતાં તમારા મોબાઇલમાં કોલ આવશે.\n\n_બદલવા: `SNOOZE {code} 30` · રદ કરવા: `CANCEL {code}`_','code,title,time'),
('reminder_created','hi','whatsapp','✅ *याद रख लिया!* [{code}]\n📌 {title}\n🕙 {time}\n📞 समय होते ही आपके मोबाइल पर कॉल आएगा।\n\n_बदलने के लिए: `SNOOZE {code} 30` · रद्द: `CANCEL {code}`_','code,title,time'),
('reminder_created','en','whatsapp','✅ *Saved!* [{code}]\n📌 {title}\n🕙 {time}\n📞 Your phone will ring at that time.\n\n_Change: `SNOOZE {code} 30` · Cancel: `CANCEL {code}`_','code,title,time'),

-- Reminder updated
('reminder_updated','gu','whatsapp','✏️ *બદલી નાખ્યું* [{code}]\n📌 {title}\n🕙 હવે: {time}','code,title,time'),
('reminder_updated','hi','whatsapp','✏️ *बदल दिया* [{code}]\n📌 {title}\n🕙 अब: {time}','code,title,time'),
('reminder_updated','en','whatsapp','✏️ *Updated* [{code}]\n📌 {title}\n🕙 Now: {time}','code,title,time'),

-- Reminder due (WhatsApp fallback when the call is not answered)
('reminder_due','gu','whatsapp','⏰ *સમય થઈ ગયો!* [{code}]\n📌 {title}\n🕙 {time}\n\nથઈ ગયું હોય તો લખો: *DONE {code}*\nપછી કરવું હોય તો: *SNOOZE {code} 15*','code,title,time'),
('reminder_due','hi','whatsapp','⏰ *समय हो गया!* [{code}]\n📌 {title}\n🕙 {time}\n\nहो गया तो लिखें: *DONE {code}*\nबाद में: *SNOOZE {code} 15*','code,title,time'),
('reminder_due','en','whatsapp','⏰ *It is time!* [{code}]\n📌 {title}\n🕙 {time}\n\nReply *DONE {code}* when finished, or *SNOOZE {code} 15*.','code,title,time'),

-- Reminder missed
('reminder_missed','gu','whatsapp','❌ *ચૂકી ગયા* [{code}]\n📌 {title}\n🕙 {time}\n\nહજી કરવું છે? લખો *SNOOZE {code} 30* — ફરી યાદ કરાવીશ.','code,title,time'),
('reminder_missed','hi','whatsapp','❌ *छूट गया* [{code}]\n📌 {title}\n🕙 {time}\n\nअभी करना है? लिखें *SNOOZE {code} 30*।','code,title,time'),
('reminder_missed','en','whatsapp','❌ *Missed* [{code}]\n📌 {title}\n🕙 {time}\n\nStill want it? Reply *SNOOZE {code} 30*.','code,title,time'),

-- Reminder done
('reminder_done','gu','whatsapp','✅ *પૂરું થયું!* [{code}]\n📌 {title}\n\n🔥 સળંગ {streak} દિવસ! 🙏','code,title,streak'),
('reminder_done','hi','whatsapp','✅ *पूरा हुआ!* [{code}]\n📌 {title}\n\n🔥 लगातार {streak} दिन! 🙏','code,title,streak'),
('reminder_done','en','whatsapp','✅ *Done!* [{code}]\n📌 {title}\n\n🔥 {streak}-day streak! 🙏','code,title,streak'),

-- Reminder snoozed
('reminder_snoozed','gu','whatsapp','⏰ *ઠીક છે* [{code}]\n{minutes} મિનિટ પછી ફરી યાદ કરાવીશ — {time}','code,minutes,time'),
('reminder_snoozed','hi','whatsapp','⏰ *ठीक है* [{code}]\n{minutes} मिनट बाद फिर याद दिलाऊंगा — {time}','code,minutes,time'),
('reminder_snoozed','en','whatsapp','⏰ *Okay* [{code}]\nI will remind you again in {minutes} minutes — {time}','code,minutes,time'),

-- Morning brief
('morning_brief','gu','whatsapp','🌅 *સુપ્રભાત {name}!*\n📅 આજે — {date}\n\n{list}\n\nકુલ {count} કામ. 🙏 જય શ્રી કૃષ્ણ','name,date,list,count'),
('morning_brief','hi','whatsapp','🌅 *सुप्रभात {name}!*\n📅 आज — {date}\n\n{list}\n\nकुल {count} काम। 🙏 जय श्री कृष्ण','name,date,list,count'),
('morning_brief','en','whatsapp','🌅 *Good morning {name}!*\n📅 Today — {date}\n\n{list}\n\n{count} task(s) planned. 🙏','name,date,list,count'),

-- Night summary
('night_summary','gu','whatsapp','🌙 *આજનો રિપોર્ટ — {date}*\n✅ પૂરાં થયેલાં કામ: {done}\n⏳ બાકી: {pending}\n❌ ચૂકી ગયેલાં: {missed}\n💰 આજે ચૂકવેલી રકમ: {amount}\n\n📅 *કાલનાં કામ ({tomorrow_count}):*\n{tomorrow_list}\n\n🔥 સળંગ {streak} દિવસ! 🙏 જય શ્રી કૃષ્ણ','date,done,pending,missed,amount,tomorrow_count,tomorrow_list,streak'),
('night_summary','hi','whatsapp','🌙 *आज की रिपोर्ट — {date}*\n✅ पूरे काम: {done}\n⏳ बाकी: {pending}\n❌ छूटे: {missed}\n💰 आज भुगतान: {amount}\n\n📅 *कल के काम ({tomorrow_count}):*\n{tomorrow_list}\n\n🔥 लगातार {streak} दिन! 🙏','date,done,pending,missed,amount,tomorrow_count,tomorrow_list,streak'),
('night_summary','en','whatsapp','🌙 *Today''s report — {date}*\n✅ Completed: {done}\n⏳ Pending: {pending}\n❌ Missed: {missed}\n💰 Paid today: {amount}\n\n📅 *Tomorrow ({tomorrow_count}):*\n{tomorrow_list}\n\n🔥 {streak}-day streak! 🙏','date,done,pending,missed,amount,tomorrow_count,tomorrow_list,streak'),

-- Payments
('payment_due','gu','whatsapp','💰 *ચુકવણી બાકી* [{code}]\n👤 {name}\n💵 {amount}\n📅 {time}\n\nચૂકવાઈ ગયું હોય તો લખો: *PAID {code} {amount_plain}*','code,name,amount,time,amount_plain'),
('payment_due','hi','whatsapp','💰 *भुगतान बाकी* [{code}]\n👤 {name}\n💵 {amount}\n📅 {time}\n\nहो गया तो लिखें: *PAID {code} {amount_plain}*','code,name,amount,time,amount_plain'),
('payment_due','en','whatsapp','💰 *Payment due* [{code}]\n👤 {name}\n💵 {amount}\n📅 {time}\n\nReply *PAID {code} {amount_plain}* once settled.','code,name,amount,time,amount_plain'),

('payment_received','gu','whatsapp','✅ *ચુકવણી નોંધાઈ*\n👤 {name}\n💵 {amount}\n📅 {time}','name,amount,time'),
('payment_received','hi','whatsapp','✅ *भुगतान दर्ज*\n👤 {name}\n💵 {amount}\n📅 {time}','name,amount,time'),
('payment_received','en','whatsapp','✅ *Payment recorded*\n👤 {name}\n💵 {amount}\n📅 {time}','name,amount,time'),

-- Weekly report
('weekly_report','gu','whatsapp','📊 *અઠવાડિયાનો રિપોર્ટ*\n✅ પૂરાં: {done}\n❌ ચૂક્યાં: {missed}\n📈 સફળતા: {rate}%\n💰 ચુકવણી: {amount}\n\n🙏 જય શ્રી કૃષ્ણ','done,missed,rate,amount'),
('weekly_report','hi','whatsapp','📊 *साप्ताहिक रिपोर्ट*\n✅ पूरे: {done}\n❌ छूटे: {missed}\n📈 सफलता: {rate}%\n💰 भुगतान: {amount}','done,missed,rate,amount'),
('weekly_report','en','whatsapp','📊 *Weekly report*\n✅ Done: {done}\n❌ Missed: {missed}\n📈 Success: {rate}%\n💰 Payments: {amount}','done,missed,rate,amount'),

-- Assignment
('assignment_received','gu','whatsapp','👥 *તમને કામ સોંપાયું* [{code}]\n📌 {title}\n🕙 {time}\n👤 સોંપનાર: {name}\n\nસ્વીકારવા લખો: *DONE {code}* (પૂરું થાય ત્યારે)','code,title,time,name'),
('assignment_received','hi','whatsapp','👥 *आपको काम सौंपा गया* [{code}]\n📌 {title}\n🕙 {time}\n👤 भेजने वाले: {name}','code,title,time,name'),
('assignment_received','en','whatsapp','👥 *Task assigned to you* [{code}]\n📌 {title}\n🕙 {time}\n👤 From: {name}','code,title,time,name'),

-- Subscription
('subscription_expiring','gu','whatsapp','⚠️ *પ્લાન પૂરો થવાનો છે*\n{name}, તમારો {plan} પ્લાન {days} દિવસમાં પૂરો થાય છે.\n\nરિન્યુ કરવા: {url}','name,plan,days,url'),
('subscription_expiring','hi','whatsapp','⚠️ *प्लान समाप्त हो रहा है*\n{name}, आपका {plan} प्लान {days} दिन में समाप्त होगा।\n\nरिन्यू करें: {url}','name,plan,days,url'),
('subscription_expiring','en','whatsapp','⚠️ *Plan expiring*\n{name}, your {plan} plan ends in {days} day(s).\n\nRenew: {url}','name,plan,days,url'),

('subscription_expired','gu','whatsapp','⛔ *પ્લાન પૂરો થયો*\n{name}, તમારો {plan} પ્લાન પૂરો થઈ ગયો છે. રિમાઇન્ડર ચાલુ રાખવા રિન્યુ કરો: {url}','name,plan,url'),
('subscription_expired','hi','whatsapp','⛔ *प्लान समाप्त*\n{name}, आपका {plan} प्लान समाप्त हो गया है। रिन्यू करें: {url}','name,plan,url'),
('subscription_expired','en','whatsapp','⛔ *Plan expired*\n{name}, your {plan} plan has ended. Renew to keep reminders running: {url}','name,plan,url'),

('plan_upgraded','gu','whatsapp','🎉 *પ્લાન એક્ટિવ થયો!*\n{name}, તમારો {plan} પ્લાન {date} સુધી ચાલુ છે. 🙏','name,plan,date'),
('plan_upgraded','hi','whatsapp','🎉 *प्लान सक्रिय!*\n{name}, आपका {plan} प्लान {date} तक चालू है। 🙏','name,plan,date'),
('plan_upgraded','en','whatsapp','🎉 *Plan activated!*\n{name}, your {plan} plan is active until {date}. 🙏','name,plan,date'),

-- Unknown number
('unknown_number_invite','gu','whatsapp','🙏 નમસ્તે! આ *કૃષ્ણ રિમાઇન્ડર* સેવા છે.\n\nઆ નંબર રજિસ્ટર થયેલો નથી, એટલે તમારો મેસેજ સેવ થયો નથી.\n\nફ્રી શરૂ કરવા: {url}\n\n_(આ મેસેજ ફક્ત એક વાર મોકલાય છે.)_','url'),
('unknown_number_invite','hi','whatsapp','🙏 नमस्ते! यह *कृष्णा रिमाइंडर* सेवा है।\n\nयह नंबर रजिस्टर नहीं है, इसलिए आपका संदेश सेव नहीं हुआ।\n\nफ्री शुरू करें: {url}','url'),
('unknown_number_invite','en','whatsapp','🙏 Hello! This is *Krishna Reminder*.\n\nThis number is not registered, so your message was not saved.\n\nStart free: {url}','url'),

-- Quota
('quota_exceeded','gu','whatsapp','⚠️ *AI મર્યાદા પૂરી થઈ*\n{name}, આ મહિનાની AI મર્યાદા પૂરી થઈ છે. તમારો મેસેજ સાદા નિયમોથી સમજ્યો છે.\n\nવધુ માટે પ્લાન અપગ્રેડ કરો: {url}','name,url'),
('quota_exceeded','hi','whatsapp','⚠️ *AI सीमा पूरी*\n{name}, इस महीने की AI सीमा पूरी हो गई है। प्लान अपग्रेड करें: {url}','name,url'),
('quota_exceeded','en','whatsapp','⚠️ *AI quota reached*\n{name}, your monthly AI quota is used up. Upgrade your plan: {url}','name,url'),

-- Help
('help','gu','whatsapp','📖 *કૃષ્ણ રિમાઇન્ડર — કમાન્ડ*\n\n✍️ સીધું લખો:\n_"કાલે સવારે 10 વાગ્યે બેંક જવાનું"_\n_"દર સોમવારે સ્ટાફ મીટિંગ"_\n_"5 તારીખે રમેશભાઈને 5000 આપવા"_\n\n📋 *યાદી* — આજનાં કામ\n📅 *આજે* — આજનું શેડ્યુલ\n⏳ *બાકી* — બાકી + ચૂકેલાં\n✅ *DONE A12* — પૂરું થયું\n⏰ *SNOOZE A12 10* — 10 મિનિટ પછી\n❌ *CANCEL A12* — રદ\n💰 *PAID A12 5000* — ચુકવણી નોંધો\n📊 *SUMMARY* — આજનો રિપોર્ટ\n🌐 *LANG GU/HI/EN* — ભાષા\n⏸️ *STOP* / ▶️ *START*\n\n🙏 જય શ્રી કૃષ્ણ',''),
('help','hi','whatsapp','📖 *कृष्णा रिमाइंडर — कमांड*\n\n✍️ सीधे लिखें:\n_"कल सुबह 10 बजे बैंक जाना है"_\n_"हर सोमवार स्टाफ मीटिंग"_\n\n📋 *सूची* · 📅 *आज* · ⏳ *बाकी*\n✅ *DONE A12* · ⏰ *SNOOZE A12 10*\n❌ *CANCEL A12* · 💰 *PAID A12 5000*\n📊 *SUMMARY* · 🌐 *LANG GU/HI/EN*\n⏸️ *STOP* / ▶️ *START*',''),
('help','en','whatsapp','📖 *Krishna Reminder — commands*\n\n✍️ Just type naturally:\n_"Call the bank tomorrow at 10 am"_\n_"Staff meeting every Monday"_\n\n📋 *LIST* · 📅 *TODAY* · ⏳ *PENDING*\n✅ *DONE A12* · ⏰ *SNOOZE A12 10*\n❌ *CANCEL A12* · 💰 *PAID A12 5000*\n📊 *SUMMARY* · 🌐 *LANG GU/HI/EN*\n⏸️ *STOP* / ▶️ *START*','');

-- ------------------------------------------------------------------- Content

INSERT IGNORE INTO `pages` (`slug`,`title`,`body`,`meta_title`,`meta_description`,`lang`,`is_published`) VALUES
('privacy-policy','Privacy Policy','<h2>Privacy Policy</h2><p>Krishna Reminder is operated by AK Computer, Dwarka, Gujarat. We collect only the data required to run the reminder service: your name, WhatsApp number, optional email, the reminders you create and the WhatsApp messages you send to our business number.</p><h3>How we use your data</h3><ul><li>To create and deliver your reminders by phone call, WhatsApp and app notification.</li><li>To send you the daily summaries you have enabled.</li><li>To provide support and to bill your subscription.</li></ul><h3>AI processing</h3><p>Message text you send is passed to Google Gemini purely to extract the date, time and task. We do not use your data to train any model.</p><h3>Your rights</h3><p>You can export all of your data or delete your account permanently from Settings at any time. Deletion removes reminders, messages and payment records within 30 days.</p><h3>Contact</h3><p>support@akdwk.in · +91 99781 23146</p>','Privacy Policy — Krishna Reminder','How Krishna Reminder collects, uses and protects your data.','en',1),
('terms','Terms of Service','<h2>Terms of Service</h2><p>By using Krishna Reminder you agree to these terms.</p><h3>Service</h3><p>Krishna Reminder delivers reminders over WhatsApp, push notification and phone-style calls on your Android device. Delivery depends on your device, network and WhatsApp availability; we take reasonable care but cannot guarantee delivery of any single reminder.</p><h3>Acceptable use</h3><p>Do not use the service to send spam, unlawful content, or to impersonate others. Automated bulk registration is not permitted.</p><h3>Payments</h3><p>Plans are prepaid for the stated duration. Prices include applicable GST where shown.</p><h3>Liability</h3><p>Our total liability is limited to the amount you paid in the previous month.</p>','Terms of Service — Krishna Reminder','Terms governing the use of Krishna Reminder.','en',1),
('refund-policy','Refund Policy','<h2>Refund Policy</h2><p>If the service does not work for you, write to us within <strong>7 days</strong> of payment at support@akdwk.in or on WhatsApp at +91 99781 23146 and we will refund the full amount to the original payment method within 7 working days.</p><p>After 7 days, subscriptions are non-refundable but you may cancel renewal at any time and keep access until the end of the paid period.</p>','Refund Policy — Krishna Reminder','7-day money-back refund policy.','en',1),
('cancellation-policy','Cancellation Policy','<h2>Cancellation Policy</h2><p>You can cancel your subscription at any time from <em>Billing</em> in your dashboard, or by messaging us on WhatsApp. Cancellation stops future renewals; your plan stays active until the paid period ends.</p><p>Account deletion is available in Settings and removes all your data.</p>','Cancellation Policy — Krishna Reminder','How to cancel your Krishna Reminder subscription.','en',1);

INSERT IGNORE INTO `faqs` (`question`,`answer`,`lang`,`sort_order`) VALUES
('કૃષ્ણ રિમાઇન્ડર કેવી રીતે કામ કરે છે?','તમે અમારા WhatsApp નંબર પર સાદી ભાષામાં મેસેજ કરો — જેમ કે "કાલે સવારે 10 વાગ્યે બેંક જવાનું છે". AI તારીખ-સમય સમજીને રિમાઇન્ડર બનાવે છે અને સમય થતાં તમારા મોબાઇલમાં કોલ આવે છે.','gu',1),
('શું મારે એપ ડાઉનલોડ કરવી પડશે?','કોલ જેવું રિમાઇન્ડર જોઈતું હોય તો Android એપ જરૂરી છે. બાકી બધું WhatsApp અને વેબસાઇટ પરથી ચાલે છે.','gu',2),
('શું ગુજરાતીમાં અવાજ સંભળાશે?','હા. એપ તમારી ભાષામાં — ગુજરાતી, હિન્દી કે અંગ્રેજી — રિમાઇન્ડર બોલીને સંભળાવે છે.','gu',3),
('ફોન સાયલન્ટ હોય તો?','અર્જન્ટ રિમાઇન્ડર સાયલન્ટ મોડમાં પણ વાગે છે. બાકીનાં તમારા Do-Not-Disturb સેટિંગ પ્રમાણે ચાલે છે.','gu',4),
('પૈસાની ઉઘરાણી યાદ રાખી શકાય?','હા. "5 તારીખે રમેશભાઈને 5000 આપવા" લખો — પેમેન્ટ રિમાઇન્ડર બની જશે અને લેણ-દેણનો હિસાબ પણ રહેશે.','gu',5),
('How does Krishna Reminder work?','Send a plain message on WhatsApp — "call the bank tomorrow at 10 am". Google Gemini reads the date, time and repetition, creates the reminder, and your Android phone rings like a real call at that moment and speaks the reminder aloud.','en',6),
('Do I need the Android app?','Only for the ringing call experience. Everything else works from WhatsApp and the web dashboard.','en',7),
('Is my data safe?','Yes. Only your verified WhatsApp number can create reminders on your account. API keys are encrypted at rest and backups are stored outside the web root.','en',8);

INSERT IGNORE INTO `testimonials` (`name`,`city`,`business`,`body`,`rating`,`lang`,`sort_order`) VALUES
('ભરતભાઈ પટેલ','દ્વારકા','કાપડની દુકાન','પહેલાં ઉઘરાણી ભૂલી જતો. હવે સમય થાય એટલે ફોન વાગે અને ગુજરાતીમાં બોલે — એક પણ પેમેન્ટ છૂટતું નથી.',5,'gu',1),
('ડૉ. મીનાબેન જોષી','જામનગર','ક્લિનિક','દર્દીઓને ફોલો-અપ કોલ કરવાનું યાદ રહે છે. WhatsApp પર લખું એટલું જ કામ.',5,'gu',2),
('Jignesh Shah','Rajkot','CA Firm','GST filing dates, staff salary, client calls — everything rings on time. The night summary on WhatsApp is brilliant.',5,'en',3);
