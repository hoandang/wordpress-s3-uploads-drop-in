Chuck these variables to your .env

    AWS_S3_PATH (/public for example)
    AWS_S3_BUCKET
    AWS_S3_BUCKET_URL (the public or cloudfront url of that bucket)
    
    (Optional if using IAM role)
    AWS_ACCESS_KEY_ID 
    AWS_SECRET_ACCESS_KEY
    AWS_SESSION_TOKEN (If using temp creds from local aws profile)

#### Inspired by https://github.com/humanmade/S3-Uploads
