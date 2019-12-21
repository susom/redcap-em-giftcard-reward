# Gift Card Rewards

An EM to help automate the reward of gift cards for survey and other participation

This EM depends on having at least one master gift card repository where the actual gift card data is stored.  Off of this main project you can have many child projects that use the gift cards as rewards.

# Setup
To setup a gift card configuration for a project, follow these steps:

    - Add the EM to the Gift Card Project
    - Setup a configuration:
    - Open the EM Configuration
    - Select the Gift Card Library project (separate project that may hold gift cards for more than one project)
    - Create a gift card instance for each type of reward (Initial Survey, Completion, etc.)
    - Make sure there are no errors displayed at the top of the modal and Save.
        
        
    - Go to the Gift Card Library Project
    - Load rewards into project (one per record)
    - All fields above Status header should be filled for each record
    - Everything below the Status header will be filled in when the card is awarded
    
# Reward Processing

Each time a record is saved in the Gift Card Project, a check will be performed to see if the participant is eligible for a reward. Once a participant is found eligible, the following steps will be performed:

    1) An unused reward meeting the monetary requirement in the Gift Card Library will be found and reserved for this participant
    2) The participant will receive an email with a link included on how to access their gift card
    3) Once the participant clicks on the link, the reward will be display on the webpage and an email will be sent with the same gift card information
    4) The record holding the reward in the Gift Card Library project will have a status of Claimed with a timestamp
    5) The record holding the participant information in the Gift Card Project will have the status Claimed
    
Once the participant clicks on the link received in the rewards email, the reward display appears as follows:

![RewardDisplay](img/reward_display.png)

Participants have the option to send themselves or someone else a copy of the reward number for future reference.

#Cron Processing

There are 2 cron jobs running daily:

Gift Card Summary

The Summary cron job will send a daily update at 6am to the Alert Email address specified in the configuration file.  The nightly summary 
displays the following data:

![Nightly Summary](img/daily_summary.png)

Logic Checker

The Logic Checker cron job will run at 9am each morning and look for the configurations who have the "Enable 9am cron job to check logic" checkbox enabled.
Configurations should enable this cron logic checker only when they have a date component to their reward logic.  This cron will ensure the
reward is sent on the appropriate date even if the record is not saved on the day it is eligible.

# Future Enhancements
    - Add the ability for the projects to download a standard Gift Card Library Template
    - Add the ability to rate limit the number of cards that can be awarded per day