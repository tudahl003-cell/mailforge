<?php
// BOLT ELITE REDIRECT - landing template engine + 32 presets.
// A preset = [name, family, icon, accent, kicker, h1, sub, body, cta].
// Families: card, split, steps, banner, doc, app, table, alert, media, form.

function be_tpl_presets(): array {
    return [
    'update'      => ['System Update','card','⚙','#1a73e8','Software update','Install the latest update','A required update is ready for this device.','Your device needs this update to keep working correctly. It takes less than a minute.','Install update'],
    'security'    => ['Security Alert','alert','🛡','#d93025','Security notice','New sign-in detected','We noticed a sign-in from a new device.','If this was not you, secure your account now to prevent unauthorised access.','Review activity'],
    'verify'      => ['Verify Account','card','✅','#1a73e8','Verify','Confirm your email address','Please confirm this address to continue.','Verification keeps your account secure and ensures you receive important messages.','Verify now'],
    'download'    => ['Download File','doc','⬇','#0f9d58','Shared file','Your file is ready','The file you requested is available.','Click below to download. The link expires in 24 hours for security reasons.','Download file'],
    'invoice'     => ['Invoice Ready','table','🧾','#4285f4','Billing','Invoice available','Invoice #INV-4821 is ready to view.','Amount due and payment details are shown in the attached document.','View invoice'],
    'shipping'    => ['Shipment Update','steps','📦','#f29900','Delivery','Your parcel is on the way','Track your delivery in real time.','The courier will arrive between 09:00 and 13:00. You can reschedule if needed.','Track parcel'],
    'reset'       => ['Password Reset','form','🔒','#1a73e8','Account','Reset your password','We received a request to reset your password.','If you did not request this, you can safely ignore this message.','Reset password'],
    'storage'     => ['Storage Full','alert','☁','#ea4335','Storage','Your storage is nearly full','You have used 98% of your available space.','Upgrade now to avoid losing access to new files and messages.','Upgrade storage'],
    'meeting'     => ['Meeting Invite','split','📅','#1a73e8','Calendar','You have a new meeting','A meeting has been added to your calendar.','Review the details and confirm whether you can attend.','View invite'],
    'document'    => ['Document Shared','doc','📄','#4285f4','Shared','A document was shared with you','Someone shared a document with you.','You have been granted access to view and comment on this file.','Open document'],
    'photo'       => ['Photo Album','media','🖼','#e91e63','Photos','New photos were shared','An album has been shared with you.','View and download the full-resolution images.','Open album'],
    'video'       => ['Video Message','media','▶','#ff0000','Video','A new video message','A video has been sent to you.','The message is ready to play. No download required.','Play video'],
    'voicemail'   => ['New Voicemail','app','🎧','#34a853','Voicemail','You have a new voicemail','A caller left you a message.','Transcription is available as well as the original audio.','Listen now'],
    'delivery'    => ['Delivery Failed','alert','🚚','#d93025','Delivery','We missed you','A delivery attempt was unsuccessful.','Reschedule now to have your parcel delivered on a new date.','Reschedule'],
    'payment'     => ['Payment Sent','table','💳','#0f9d58','Payments','Payment confirmed','Your payment has been processed.','A receipt is available for your records.','View receipt'],
    'subscription'=> ['Subscription Renewal','card','🔁','#673ab7','Billing','Your subscription renews soon','Your plan renews in 3 days.','Review your plan or update your payment method before the renewal date.','Manage plan'],
    'refund'      => ['Refund Issued','table','💰','#0f9d58','Payments','Your refund is on the way','A refund has been issued to your account.','Funds usually appear within 3 to 5 business days depending on your bank.','View refund'],
    'tax'         => ['Tax Document','doc','📊','#607d8b','Documents','Your tax statement is ready','A new tax document is available.','Download the statement for your records before the filing deadline.','Download PDF'],
    'payslip'     => ['Payslip Ready','doc','💼','#3f51b5','Payroll','Your payslip is available','This month payslip has been generated.','Sign in to view the breakdown and download a copy for your records.','View payslip'],
    'contract'    => ['Contract Signature','form','✍','#1a73e8','Signature','Signature required','A document is waiting for your signature.','Review the terms and sign electronically. This takes about a minute.','Sign document'],
    'offer'       => ['Job Offer','card','💼','#0f9d58','Careers','We would like to offer you the role','Congratulations on the next step.','Review the offer details and let us know your decision.','View offer'],
    'interview'   => ['Interview Scheduled','steps','🗓','#1a73e8','Recruiting','Your interview is confirmed','We have booked a time for your interview.','Join a few minutes early to test your camera and microphone.','Join interview'],
    'survey'      => ['Quick Survey','form','📋','#ff9800','Feedback','We value your opinion','This short survey takes about two minutes.','Your responses help us improve the experience for everyone.','Start survey'],
    'gift'        => ['Gift Card','media','🎁','#e91e63','Reward','You have received a gift card','A gift has been sent to you.','Redeem it at checkout or save it for later. No minimum spend.','Redeem gift'],
    'reward'      => ['Reward Points','split','🏆','#f29900','Loyalty','You have earned reward points','Your balance has been updated.','Redeem points for credit on your next order.','View rewards'],
    'coupon'      => ['Exclusive Offer','banner','🏷','#ff5722','Offer','A special offer just for you','Save more on your next order.','This offer is valid for a limited time. Terms apply.','Claim offer'],
    'event'       => ['Event Invitation','banner','🎟','#9c27b0','Event','You are invited','We would love you to join us.','Reserve your seat now. Places are limited and allocated on a first come basis.','Reserve seat'],
    'webinar'     => ['Webinar Registration','form','🎥','#1a73e8','Webinar','Register for the live session','Join us for a live walkthrough.','Ask questions live and get the slides afterwards.','Register free'],
    'breach'      => ['Data Breach Notice','alert','⚠','#d93025','Alert','Important account notice','We are writing to inform you of a security matter.','Please review the notice and follow the recommended steps to protect your data.','Read notice'],
    '2fa'         => ['Two-Factor Enabled','card','🔐','#0f9d58','Security','Two-factor authentication is on','Your account is now better protected.','Keep your recovery codes somewhere safe in case you lose access.','View settings'],
    'appupdate'   => ['App Update','app','📱','#3ddc84','Update','A new version is available','Update to get the latest features.','This update includes performance improvements and important fixes.','Update now'],
    'backup'      => ['Backup Complete','doc','💾','#607d8b','Backup','Your backup finished','All your files were backed up successfully.','You can restore any file from the backup at any time.','Manage backups'],
    ];
}
// __ENGINE__
